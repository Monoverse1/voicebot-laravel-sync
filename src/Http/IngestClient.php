<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Http;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Exceptions\NotPairedException;
use Monoverse\VoicebotSync\Exceptions\TransientException;
use Monoverse\VoicebotSync\Exceptions\VoicebotSyncException;
use Monoverse\VoicebotSync\Protocol\HmacSigner;
use Monoverse\VoicebotSync\Protocol\Protocol;
use Monoverse\VoicebotSync\Support\SecretStore;

/**
 * Talks the VoiceBot Sync Protocol v2. The body string that is HASHED is the exact
 * string SENT (withBody), so the server's body_sha256 always matches — never let
 * Laravel re-encode the array, or the signature breaks.
 *
 * Retry policy is split by signing: the server consumes the nonce (atomic SET NX)
 * BEFORE it can return 429/5xx, so auto-retrying a signed call would replay a spent
 * nonce and earn a 401. Signed calls therefore retry on ConnectionException ONLY;
 * the unsigned pair() and the no-HMAC uploadFile() PUT may retry on 429/5xx.
 */
final class IngestClient
{
    /** @param array<string, mixed> $http */
    public function __construct(
        private readonly SecretStore $secrets,
        private readonly string $configBaseUrl,
        private readonly array $http,
        private readonly ?string $siteUrl = null,
    ) {}

    /**
     * Unauthenticated pairing handshake.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{tenant_id: string, shared_secret_b64: string, ingest_url: string}
     */
    public function pair(string $pairCode, string $siteUrl, array $metadata = []): array
    {
        $body = array_merge([
            'pair_code' => $pairCode,
            'site_url' => $siteUrl,
            'plugin_version' => Protocol::pluginVersionHeader(),
            'php_version' => PHP_VERSION,
        ], $metadata);

        $bodyString = $this->encode($body);
        // pair() is unsigned (no nonce consumed) → safe to retry on 429/5xx.
        $response = $this->base($this->assertSecure($this->configBaseUrl), retryOnHttpError: true)
            ->withHeaders([
                'Content-Type' => 'application/json',
                Protocol::HEADER_PROTOCOL => Protocol::VERSION,
                Protocol::HEADER_PLUGIN_VER => Protocol::pluginVersionHeader(),
            ])
            ->withBody($bodyString, 'application/json')
            ->post(Protocol::PATH_PAIR);

        $data = $this->decode($response, 'pair');

        return $this->assertPairResponse($data, 'pair');
    }

    /**
     * Unauthenticated pair-by-key handshake. Mints the same connection as the legacy
     * pair-code path, gated on the publishable key's canonical domain.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{tenant_id: string, shared_secret_b64: string, ingest_url: string}
     */
    public function pairByKey(string $publicKey, string $siteUrl, array $metadata = []): array
    {
        $body = array_merge([
            'public_key' => $publicKey,
            'site_url' => $siteUrl,
            'plugin_version' => Protocol::pluginVersionHeader(),
            'php_version' => PHP_VERSION,
        ], $metadata);

        $bodyString = $this->encode($body);
        // pairByKey() is unsigned (no nonce consumed) → safe to retry on 429/5xx.
        $response = $this->base($this->assertSecure($this->configBaseUrl), retryOnHttpError: true)
            ->withHeaders([
                'Content-Type' => 'application/json',
                Protocol::HEADER_PROTOCOL => Protocol::VERSION,
                Protocol::HEADER_PLUGIN_VER => Protocol::pluginVersionHeader(),
            ])
            ->withBody($bodyString, 'application/json')
            ->post(Protocol::PATH_PAIR_BY_KEY);

        if ($response->failed()) {
            throw $this->pairByKeyError($response);
        }

        $json = $response->json();
        /** @var array<string, mixed> $data */
        $data = is_array($json) ? $json : [];

        return $this->assertPairResponse($data, 'pair-by-key');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{tenant_id: string, shared_secret_b64: string, ingest_url: string}
     */
    private function assertPairResponse(array $data, string $op): array
    {
        foreach (['tenant_id', 'shared_secret_b64', 'ingest_url'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new ConfigException("{$op} response missing `{$key}`");
            }
        }

        /** @var array{tenant_id: string, shared_secret_b64: string, ingest_url: string} $data */
        return $data;
    }

    private function pairByKeyError(Response $response): VoicebotSyncException
    {
        $status = $response->status();
        $code = $this->errorCode($response);
        $message = match (true) {
            $status === 401 || $code === 'invalid_key' => 'invalid_key: the publishable key is unknown or inactive. Check VOICEBOT_PUBLIC_KEY in your VoiceBot dashboard.',
            $status === 403 || $code === 'domain_mismatch' => 'domain_mismatch: site_url does not match the domain bound to this key. Set VOICEBOT_SITE_URL to the storefront domain registered for this key.',
            $status === 409 || $code === 'key_has_no_domain' => 'key_has_no_domain: this publishable key has no bound domain yet. Set its canonical domain in the VoiceBot dashboard before pairing.',
            default => "pair-by-key failed ({$status} {$code})",
        };

        return $this->classify($status, $message);
    }

    /**
     * @param  array<string, int>  $expectedCounts
     * @return array{sync_id: string, upload_url: string}
     */
    public function init(string $syncType, array $expectedCounts): array
    {
        $data = $this->decode(
            $this->signed('POST', Protocol::PATH_INIT, [
                'sync_type' => $syncType,
                'expected_counts' => $expectedCounts,
            ]),
            'init',
        );
        if (! isset($data['sync_id'], $data['upload_url']) || ! is_string($data['sync_id']) || ! is_string($data['upload_url'])) {
            throw new ConfigException('init response missing sync_id/upload_url');
        }

        /** @var array{sync_id: string, upload_url: string} $data */
        return $data;
    }

    /**
     * PUT the gzipped-NDJSON snapshot to the opaque, single-use upload URL (NO HMAC).
     * Streams the file from disk so a large snapshot is never held in memory.
     */
    public function uploadFile(string $uploadUrl, string $gzipPath): void
    {
        $stream = fopen($gzipPath, 'rb');
        if ($stream === false) {
            throw new ConfigException("could not open snapshot for upload: {$gzipPath}");
        }
        try {
            // Wrap the resource in a PSR-7 stream so the snapshot is sent in chunks
            // straight from disk and never fully materialised in memory. The upload URL
            // carries no nonce, so retry-on-429/5xx is safe here.
            $response = $this->base($uploadUrl, retryOnHttpError: true)
                ->withBody(Utils::streamFor($stream), 'application/gzip')
                ->put($uploadUrl);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($response->failed()) {
            throw $this->classify($response->status(), "upload failed: HTTP {$response->status()}");
        }
    }

    public function finalize(string $syncId, string $idempotencyKey): void
    {
        $this->decode(
            $this->signed('POST', Protocol::PATH_FINALIZE, ['sync_id' => $syncId], [
                Protocol::HEADER_IDEMPOTENCY => $idempotencyKey,
            ]),
            'finalize',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{processed: int, idempotent_replay: bool, errors: list<array<string, mixed>>}
     */
    public function events(string $batchId, array $operations, string $idempotencyKey): array
    {
        $data = $this->decode(
            $this->signed('POST', Protocol::PATH_EVENTS, [
                'batch_id' => $batchId,
                'events' => $operations,
            ], [Protocol::HEADER_IDEMPOTENCY => $idempotencyKey]),
            'events',
        );
        $rawErrors = $data['errors'] ?? [];
        /** @var list<array<string, mixed>> $errors */
        $errors = [];
        if (is_array($rawErrors)) {
            foreach ($rawErrors as $err) {
                if (is_array($err)) {
                    /** @var array<string, mixed> $normalized */
                    $normalized = [];
                    foreach ($err as $k => $v) {
                        $normalized[(string) $k] = $v;
                    }
                    $errors[] = $normalized;
                }
            }
        }

        return [
            'processed' => self::intOf($data['processed'] ?? 0),
            'idempotent_replay' => (bool) ($data['idempotent_replay'] ?? false),
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->decode($this->signed('GET', Protocol::PATH_STATUS, null), 'status');
    }

    /** Tell the backend to disconnect this paired connection. Signed, empty body. */
    public function unpair(): void
    {
        $this->decode($this->signed('POST', Protocol::PATH_UNPAIR, null), 'unpair');
    }

    /**
     * @param  array<string, mixed>|null  $jsonBody
     * @param  array<string, string>  $extraHeaders
     */
    private function signed(string $method, string $path, ?array $jsonBody, array $extraHeaders = []): Response
    {
        $tenantId = $this->secrets->tenantId();
        $secret = $this->secrets->secretRaw();
        if ($tenantId === null || $secret === null) {
            throw NotPairedException::make();
        }

        $bodyString = $jsonBody === null ? '' : $this->encode($jsonBody);
        $timestamp = (string) time();
        $nonce = HmacSigner::nonce();
        $signature = (new HmacSigner($secret))->sign(
            $method,
            $path,
            $timestamp,
            $nonce,
            HmacSigner::bodyHash($bodyString),
        );

        $headers = array_merge([
            Protocol::HEADER_TENANT => $tenantId,
            Protocol::HEADER_TIMESTAMP => $timestamp,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_SIGNATURE => $signature,
            Protocol::HEADER_PROTOCOL => Protocol::VERSION,
            Protocol::HEADER_PLUGIN_VER => Protocol::pluginVersionHeader(),
            Protocol::HEADER_SITE_URL => $this->siteUrl ?? '',
        ], $extraHeaders);

        // Signed → ConnectionException-only retry: the nonce is already spent server-side,
        // so an auto-retry on 429/5xx would replay it and earn a 401 nonce_replay.
        $request = $this->base($this->ingestBaseUrl(), retryOnHttpError: false)->withHeaders($headers);

        if ($method === 'GET') {
            return $request->get($path);
        }

        return $request->withBody($bodyString, 'application/json')->send($method, $path);
    }

    private function base(string $baseUrl, bool $retryOnHttpError): PendingRequest
    {
        $retry = is_array($this->http['retry'] ?? null) ? $this->http['retry'] : [];
        $times = self::intOf($retry['times'] ?? 3);
        $baseMs = self::intOf($retry['base_ms'] ?? 250);
        $maxMs = self::intOf($retry['max_ms'] ?? 5000);

        return Http::baseUrl($baseUrl)
            ->timeout(self::intOf($this->http['timeout'] ?? 30))
            ->connectTimeout(self::intOf($this->http['connect_timeout'] ?? 10))
            ->retry($times, function (int $attempt, mixed $e) use ($baseMs, $maxMs): int {
                $retryAfterMs = $this->retryAfterMs($e instanceof \Throwable ? $e : null);
                if ($retryAfterMs !== null) {
                    return min($retryAfterMs, $maxMs);
                }
                $backoff = (int) min($baseMs * (2 ** ($attempt - 1)), $maxMs);

                return $backoff + random_int(0, 100);
            }, function (\Throwable $e) use ($retryOnHttpError): bool {
                if ($e instanceof ConnectionException) {
                    return true;
                }
                if ($retryOnHttpError && $e instanceof RequestException) {
                    $status = $e->response->status();

                    return $status === 429 || $status >= 500;
                }

                return false;
            }, throw: false);
    }

    private function retryAfterMs(?\Throwable $e): ?int
    {
        if (! $e instanceof RequestException) {
            return null;
        }
        $header = $e->response->header('Retry-After');
        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return ((int) $header) * 1000;
    }

    private function ingestBaseUrl(): string
    {
        return $this->assertSecure(rtrim($this->secrets->ingestUrl() ?? $this->configBaseUrl, '/'));
    }

    /**
     * Reject plaintext http:// transport (HMAC over http leaks the signature to MITM).
     * localhost/127.0.0.1 stay allowed for local dev. Returns the URL for chaining.
     */
    private function assertSecure(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isLocal = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
        if (! $isLocal && ! str_starts_with(strtolower($url), 'https://')) {
            throw new ConfigException("VoiceBot base/ingest URL must use https:// (got: {$url})");
        }

        return $url;
    }

    private function classify(int $status, string $message): VoicebotSyncException
    {
        // 429 + 5xx are retryable upstream conditions; any other 4xx is a config-class error.
        return ($status === 429 || $status >= 500)
            ? new TransientException($message)
            : new ConfigException($message);
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $op): array
    {
        if ($response->failed()) {
            throw $this->classify($response->status(), "{$op} failed ({$response->status()} {$this->errorCode($response)})");
        }
        $json = $response->json();

        /** @var array<string, mixed> $out */
        $out = is_array($json) ? $json : [];

        return $out;
    }

    private function errorCode(Response $response): string
    {
        $body = $response->json();
        if (is_array($body) && isset($body['error']) && is_array($body['error']) && isset($body['error']['code'])) {
            return self::strOf($body['error']['code']);
        }

        return 'http_'.$response->status();
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function strOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

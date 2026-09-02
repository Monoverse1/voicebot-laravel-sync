<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Protocol;

use Illuminate\Support\Facades\Cache;
use Monoverse\VoicebotSync\Support\SecretStore;

final class InboundVerifier
{
    public function __construct(private readonly SecretStore $secrets) {}

    public function verify(
        string $method,
        string $inboundPath,
        string $expectedPath,
        string $tenantId,
        string $timestamp,
        string $nonce,
        string $bodyHash,
        string $providedSignature,
    ): InboundVerificationResult {
        if ($tenantId === '' || $timestamp === '' || $nonce === '' || $providedSignature === '') {
            return InboundVerificationResult::reject('missing_signature_headers', 401);
        }

        $secret = $this->secrets->secretRaw();
        $expectedTenant = $this->secrets->tenantId();
        if ($secret === null || $expectedTenant === null) {
            return InboundVerificationResult::reject('not_paired', 401);
        }

        if (! hash_equals($expectedTenant, $tenantId)) {
            return InboundVerificationResult::reject('tenant_mismatch', 403);
        }

        if (! ctype_digit($timestamp)) {
            return InboundVerificationResult::reject('bad_timestamp', 401);
        }

        if (abs(time() - (int) $timestamp) > Protocol::REPLAY_WINDOW_SECONDS) {
            return InboundVerificationResult::reject('stale_timestamp', 401);
        }

        if ($inboundPath !== $expectedPath) {
            return InboundVerificationResult::reject('inbound_path_mismatch', 401);
        }

        $expected = hash_hmac(
            'sha256',
            strtoupper($method)."\n".$inboundPath."\n".$timestamp."\n".$nonce."\n".$bodyHash,
            $secret,
        );
        if (! hash_equals($expected, $providedSignature)) {
            return InboundVerificationResult::reject('bad_signature', 401);
        }

        if (! $this->claimNonce($expectedTenant, $nonce)) {
            return InboundVerificationResult::reject('nonce_replay', 401);
        }

        return InboundVerificationResult::accept();
    }

    private function claimNonce(string $tenantId, string $nonce): bool
    {
        $key = "voicebot_nonce:{$tenantId}:{$nonce}";

        return Cache::add($key, true, Protocol::NONCE_TTL_SECONDS);
    }
}

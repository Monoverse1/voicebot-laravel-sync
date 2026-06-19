<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Support\SecretStore;
use Throwable;

/**
 * Pairing handshake. Accepts a publishable key (pk_...) — gated on the key's bound
 * domain — or a legacy one-time VB-XXXX-XXXX code, persists the minted tenant id +
 * shared secret (encrypted via SecretStore), and prints the tenant id. The shared
 * secret is NEVER printed or logged.
 */
final class PairCommand extends Command
{
    private const PUBLIC_KEY_PREFIX = 'pk_';

    protected $signature = 'voicebot:pair {credential? : Publishable key (pk_...) or legacy pair code (VB-XXXX-XXXX); falls back to VOICEBOT_PUBLIC_KEY then VOICEBOT_PAIR_CODE}';

    protected $description = 'Pair this app with VoiceBot using a publishable key (pk_...) or a one-time pair code';

    public function handle(IngestClient $client, SecretStore $secrets, Config $config): int
    {
        $credential = $this->resolveCredential($config);
        if ($credential === null) {
            $this->error('No pairing credential. Pass a pk_ key or pair code as an argument, or set VOICEBOT_PUBLIC_KEY / VOICEBOT_PAIR_CODE.');

            return self::INVALID;
        }

        if ($secrets->isPaired()) {
            $this->warn('Already paired (tenant '.$secrets->tenantId().'). Run `voicebot:unpair` first to re-pair.');

            return self::INVALID;
        }

        $siteUrlRaw = $config->get('voicebot.site_url', '');
        $siteUrl = is_scalar($siteUrlRaw) ? (string) $siteUrlRaw : '';
        if ($siteUrl === '') {
            $this->error('voicebot.site_url is empty. Set APP_URL or VOICEBOT_SITE_URL.');

            return self::INVALID;
        }

        $byKey = str_starts_with($credential, self::PUBLIC_KEY_PREFIX);

        try {
            $result = $byKey
                ? $client->pairByKey($credential, $siteUrl, $this->metadata())
                : $client->pair($credential, $siteUrl, $this->metadata());
        } catch (ConfigException $e) {
            // Bad/used credential, domain mismatch, malformed response, insecure URL — the operator must act.
            $this->error('Pairing failed: '.$e->getMessage());
            if (str_contains($e->getMessage(), 'domain_mismatch')) {
                $this->line('  Hint: VOICEBOT_SITE_URL must match the storefront domain bound to this key (got: '.$siteUrl.').');
            }

            return self::INVALID;
        } catch (Throwable $e) {
            // Transient (network/5xx) — cron/CI may retry.
            $this->error('Pairing failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $secretRaw = base64_decode($result['shared_secret_b64'], true);
        if ($secretRaw === false || $secretRaw === '') {
            $this->error('Pairing response carried an invalid shared secret.');

            return self::FAILURE;
        }
        $secrets->store($result['tenant_id'], $secretRaw, $result['ingest_url']);

        $this->info('Paired with VoiceBot.');
        $this->line('  Tenant: '.$result['tenant_id']);
        $this->line('  Ingest: '.rtrim($result['ingest_url'], '/'));
        $this->newLine();
        $this->comment('Next: `php artisan voicebot:doctor` then `php artisan voicebot:sync --full`.');

        return self::SUCCESS;
    }

    private function resolveCredential(Config $config): ?string
    {
        $arg = $this->argument('credential');
        if (is_string($arg) && $arg !== '') {
            return trim($arg);
        }

        foreach (['voicebot.public_key', 'voicebot.pair_code'] as $key) {
            $value = $config->get($key);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function metadata(): array
    {
        $locale = config('app.locale');
        $timezone = config('app.timezone');

        return array_filter([
            'locale' => is_string($locale) ? $locale : '',
            'timezone' => is_string($timezone) ? $timezone : '',
        ], static fn (string $v): bool => $v !== '');
    }
}

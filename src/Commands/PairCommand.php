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
 * Pairing handshake. Consumes a one-time VB-XXXX-XXXX code, persists the minted
 * tenant id + shared secret (encrypted via SecretStore), and prints the tenant id.
 * The shared secret is NEVER printed or logged.
 */
final class PairCommand extends Command
{
    protected $signature = 'voicebot:pair {code? : One-time pair code (VB-XXXX-XXXX); falls back to VOICEBOT_PAIR_CODE}';

    protected $description = 'Pair this app with VoiceBot using a one-time code';

    public function handle(IngestClient $client, SecretStore $secrets, Config $config): int
    {
        $code = $this->resolveCode($config);
        if ($code === null) {
            $this->error('No pair code. Pass it as an argument or set VOICEBOT_PAIR_CODE.');

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

        try {
            $result = $client->pair($code, $siteUrl, $this->metadata());
        } catch (ConfigException $e) {
            // Bad/used code, malformed response, insecure URL — the operator must act.
            $this->error('Pairing failed: '.$e->getMessage());

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

    private function resolveCode(Config $config): ?string
    {
        $arg = $this->argument('code');
        $code = is_string($arg) && $arg !== '' ? $arg : $config->get('voicebot.pair_code');

        return is_string($code) && $code !== '' ? trim($code) : null;
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

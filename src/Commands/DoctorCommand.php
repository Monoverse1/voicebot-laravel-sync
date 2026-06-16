<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Commands;

use Illuminate\Console\Command;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Sources\HostProfileSource;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Sync\SyncRunner;
use Throwable;

/**
 * Pre-flight: fails LOUDLY before the first real push. Verifies pairing, backend
 * reachability, and — per enabled kind — a resolvable source, a non-empty map, the
 * backend-required payload fields on a sampled mapped row, and emits one sample
 * mapped record. Pushes nothing.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'voicebot:doctor {--samples=1 : Sample rows to inspect per kind}';

    protected $description = 'Diagnose VoiceBot sync config before pushing (no data is sent)';

    private bool $failed = false;

    public function handle(SyncRunner $runner, SecretStore $secrets, IngestClient $client): int
    {
        $this->line('VoiceBot sync doctor');
        $this->line(str_repeat('-', 40));

        $paired = $this->checkPaired($secrets);
        if ($paired) {
            $this->checkBackend($client);
        }
        $this->checkSources($runner);

        $this->newLine();
        if ($this->failed) {
            $this->error('FAIL — fix the items above before syncing.');

            return self::FAILURE;
        }
        $this->info('PASS — configuration looks healthy.');

        return self::SUCCESS;
    }

    private function checkPaired(SecretStore $secrets): bool
    {
        if (! $secrets->isPaired()) {
            $this->reportFail('pairing', 'not paired — run `php artisan voicebot:pair <code>`');

            return false;
        }
        $this->pass('pairing', 'paired (tenant '.$secrets->tenantId().')');

        return true;
    }

    private function checkBackend(IngestClient $client): void
    {
        try {
            $status = $client->status();
        } catch (Throwable $e) {
            $this->reportFail('backend', 'unreachable or rejected: '.$e->getMessage());

            return;
        }
        $provider = is_string($status['provider_status'] ?? null) ? $status['provider_status'] : 'unknown';
        $this->pass('backend', 'reachable (provider_status='.$provider.')');
    }

    private function checkSources(SyncRunner $runner): void
    {
        try {
            $sources = $runner->sources();
        } catch (Throwable $e) {
            $this->reportFail('entities', 'could not resolve sources: '.$e->getMessage());

            return;
        }
        if ($sources === []) {
            $this->reportFail('entities', 'no enabled entity kinds — enable at least one in config/voicebot.php');

            return;
        }
        $hasHostProfile = false;
        foreach ($sources as $source) {
            $this->checkSource($source);
            if ($source instanceof HostProfileSource) {
                $hasHostProfile = true;
            }
        }

        if (! $hasHostProfile) {
            $this->warnLine('host_profile', 'not enabled — the bot will only use catalog and navigation tools; enable voicebot.entities.host_profile to grant cart/checkout/variant capabilities');
        }
    }

    private function checkSource(EntitySource $source): void
    {
        $kind = $source->kind();
        $label = 'entity:'.$kind->value;

        try {
            $count = $source->expectedCount();
        } catch (Throwable $e) {
            $this->reportFail($label, $e->getMessage());

            return;
        }
        if ($count === 0) {
            $this->warnLine($label, 'resolves but has 0 rows — a full snapshot would refuse to push');

            return;
        }

        try {
            /** @var CanonicalEntity|null $sample */
            $sample = $source->upserts(null)->first();
        } catch (Throwable $e) {
            $this->reportFail($label, 'mapping a sample row threw: '.$e->getMessage());

            return;
        }
        if (! $sample instanceof CanonicalEntity) {
            $this->warnLine($label, "{$count} rows reported but none streamed — check your query");

            return;
        }

        $missing = $this->missingRequiredKeys($kind, $sample);
        if ($missing !== []) {
            $this->reportFail($label, 'sample row missing required payload key(s): '.implode(', ', $missing).' — fix the config map');

            return;
        }

        $this->pass($label, "{$count} rows, sample maps cleanly");
        $this->line('    '.$this->sampleJson($sample));
    }

    /**
     * @return list<string>
     */
    private function missingRequiredKeys(EntityKind $kind, CanonicalEntity $sample): array
    {
        $missing = [];
        foreach ($kind->requiredPayloadKeys() as $key) {
            $value = $sample->payload[$key] ?? null;
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function sampleJson(CanonicalEntity $sample): string
    {
        $json = json_encode($sample->toNdjsonRecord(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = $json === false ? '<unencodable>' : $json;

        return mb_strlen($json) > 240 ? mb_substr($json, 0, 240).'…' : $json;
    }

    private function pass(string $label, string $message): void
    {
        $this->line(sprintf('  <fg=green>PASS</> %-16s %s', $label, $message));
    }

    // Named reportFail (not fail) — Laravel\Console\Command::fail() exists and throws.
    private function reportFail(string $label, string $message): void
    {
        $this->failed = true;
        $this->line(sprintf('  <fg=red>FAIL</> %-16s %s', $label, $message));
    }

    private function warnLine(string $label, string $message): void
    {
        $this->line(sprintf('  <fg=yellow>WARN</> %-16s %s', $label, $message));
    }
}

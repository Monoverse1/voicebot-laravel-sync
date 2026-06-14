<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Exceptions\NotPairedException;
use Monoverse\VoicebotSync\Jobs\SyncCatalogJob;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Sync\SyncRunner;
use Throwable;

/**
 * Pushes the catalog to VoiceBot. Default is an incremental delta; `--full` streams
 * a complete snapshot. `--dry-run` computes and prints what WOULD be sent without
 * pushing. `--queue` dispatches the work to a worker and returns immediately.
 *
 * Exit codes are cron-friendly: 1 (transient) so the scheduler/monitor alerts and
 * retries; 2 (config) for "not paired" and bad input which a retry won't fix.
 */
final class SyncCommand extends Command
{
    protected $signature = 'voicebot:sync
        {--full : Stream a full snapshot instead of an incremental delta}
        {--since= : Delta only — override the watermark (ISO-8601 / any strtotime value)}
        {--dry-run : Compute and print what would be sent; push nothing}
        {--queue : Dispatch the sync to a queue worker and return immediately}';

    protected $description = 'Sync the catalog to VoiceBot (delta by default; --full for a snapshot)';

    public function handle(SyncRunner $runner, SecretStore $secrets): int
    {
        if (! $secrets->isPaired()) {
            $this->error('Not paired. Run `php artisan voicebot:pair <code>` first.');

            return self::INVALID;
        }

        $full = (bool) $this->option('full');
        $dryRun = (bool) $this->option('dry-run');

        $since = $this->resolveSince($full);
        if ($since === false) {
            return self::INVALID;
        }

        if ($this->option('queue')) {
            if ($dryRun) {
                $this->error('--dry-run cannot be combined with --queue.');

                return self::INVALID;
            }
            SyncCatalogJob::dispatch($full);
            $this->info('Dispatched '.($full ? 'full' : 'delta').' sync to the queue.');

            return self::SUCCESS;
        }

        try {
            return $full
                ? $this->runFull($runner, $dryRun)
                : $this->runDelta($runner, $dryRun, $since);
        } catch (NotPairedException|ConfigException $e) {
            // Config-class failures (not paired, empty/oversize snapshot, bad model)
            // won't fix on retry → INVALID so cron stops hammering.
            $this->error('Sync failed: '.$e->getMessage());

            return self::INVALID;
        } catch (Throwable $e) {
            // Transient and unclassified failures → FAILURE so cron alerts and retries.
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function runFull(SyncRunner $runner, bool $dryRun): int
    {
        $result = $runner->full($dryRun);
        $this->info(($dryRun ? '[dry-run] ' : '').'Full snapshot: '.$result->total.' records.');
        foreach ($result->counts as $kind => $count) {
            $this->line(sprintf('  %-18s %d', $kind, $count));
        }
        if ($dryRun) {
            $this->comment('Nothing was pushed (--dry-run). Drop the flag to send.');
        } elseif ($result->syncId !== null) {
            $this->line('  sync_id: '.$result->syncId);
        }

        return self::SUCCESS;
    }

    private function runDelta(SyncRunner $runner, bool $dryRun, ?CarbonImmutable $since): int
    {
        $result = $runner->delta($dryRun, $since);
        $this->info(sprintf(
            '%sDelta: %d ops in %d batch(es)%s.',
            $dryRun ? '[dry-run] ' : '',
            $result->processed,
            $result->batches,
            $result->deadLettered > 0 ? ', '.$result->deadLettered.' dead-lettered' : '',
        ));
        if ($dryRun) {
            $this->comment('Nothing was pushed (--dry-run). Drop the flag to send.');
        }

        // A delta that parked ops in the dead-letter table is a partial failure: alert.
        return $result->deadLettered > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return CarbonImmutable|null|false false signals an invalid-option exit */
    private function resolveSince(bool $full): CarbonImmutable|null|false
    {
        $raw = $this->option('since');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if ($full) {
            $this->error('--since only applies to delta syncs; drop --full or --since.');

            return false;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            $this->error('--since is not a valid date/time: '.$raw);

            return false;
        }
    }
}

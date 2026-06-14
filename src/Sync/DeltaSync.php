<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Exceptions\VoicebotSyncException;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Support\DeadLetter;
use Monoverse\VoicebotSync\Support\Watermark;

/**
 * Incremental delta: per kind, stream rows changed since the watermark as /events
 * batches (capped by op-count and bytes). The watermark advances only when every
 * batch for that kind succeeded; a permanently-failed batch is dead-lettered and
 * the window is retried next run.
 */
final class DeltaSync
{
    public function __construct(
        private readonly IngestClient $client,
        private readonly Watermark $watermark,
        private readonly DeadLetter $deadLetter,
        private readonly int $maxOps = 500,
        private readonly int $maxBytes = 5_242_880,
    ) {}

    /**
     * @param  list<EntitySource>  $sources
     * @param  CarbonImmutable|null  $sinceOverride  when set, ignores the stored watermark
     *                                               and selects rows changed after this instant (one-off catch-up runs)
     */
    public function run(array $sources, bool $dryRun = false, ?CarbonImmutable $sinceOverride = null): DeltaSyncResult
    {
        $processed = 0;
        $batches = 0;
        $deadLettered = 0;

        foreach ($sources as $source) {
            $kind = $source->kind()->value;
            $runStart = CarbonImmutable::now();
            $since = $sinceOverride ?? $this->watermark->get($kind);

            /** @var list<array<string, mixed>> $buffer */
            $buffer = [];
            $bytes = 0;
            $kindFailed = false;

            $flush = function () use (&$buffer, &$bytes, &$batches, &$processed, &$deadLettered, &$kindFailed, $dryRun): void {
                if ($buffer === []) {
                    return;
                }
                $ops = $buffer;
                $buffer = [];
                $bytes = 0;
                if ($dryRun) {
                    $processed += count($ops);
                    $batches++;

                    return;
                }
                // batch_id deliberately doubles as the Idempotency-Key: a network retry
                // of the SAME batch must replay (not re-apply) — keep them coupled.
                $batchId = (string) Str::uuid();
                try {
                    $result = $this->client->events($batchId, $ops, $batchId);
                    $processed += $result['processed'];
                    $batches++;
                } catch (VoicebotSyncException $e) {
                    $deadLettered += $this->deadLetter->record($batchId, $ops, $e->getMessage());
                    $kindFailed = true;
                }
            };

            foreach ($source->upserts($since) as $entity) {
                $this->append($buffer, $bytes, $entity->toUpsertOperation(), $flush);
            }
            foreach ($source->deletes($since) as $deleteOp) {
                $this->append($buffer, $bytes, $deleteOp, $flush);
            }
            $flush();

            if (! $kindFailed && ! $dryRun) {
                $this->watermark->set($kind, $runStart);
            }
        }

        return new DeltaSyncResult($processed, $batches, $deadLettered, $dryRun);
    }

    /**
     * @param  list<array<string, mixed>>  $buffer
     * @param  array<string, mixed>  $op
     */
    private function append(array &$buffer, int &$bytes, array $op, callable $flush): void
    {
        $opBytes = strlen((string) json_encode($op, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($buffer !== [] && (count($buffer) >= $this->maxOps || $bytes + $opBytes > $this->maxBytes)) {
            $flush();
        }
        $buffer[] = $op;
        $bytes += $opBytes;
    }
}

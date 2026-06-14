<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Monoverse\VoicebotSync\Models\VoicebotSyncState;

/**
 * Per-entity-kind incremental watermark. The delta sync pushes rows whose
 * updated_at is strictly greater than the stored watermark, then advances it to the
 * instant the run STARTED (CarbonImmutable::now() captured before reading rows) —
 * NOT to max(updated_at) of the rows sent.
 *
 * Why run-start, not max(updated_at): a row written DURING the run (after we captured
 * the start instant but before its kind finished streaming) carries an updated_at
 * later than start. Advancing to max(updated_at) would skip that row forever (it is
 * <= the new watermark yet was never read). Advancing to run-start re-includes it on
 * the next run — at-least-once delivery, which upserts make idempotent. Only advances
 * after every batch for the kind succeeded.
 */
final class Watermark
{
    public function get(string $entityKind): ?CarbonImmutable
    {
        $row = VoicebotSyncState::query()->where('entity_kind', $entityKind)->first();
        $value = $row?->watermark;

        return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : null;
    }

    public function set(string $entityKind, CarbonInterface $watermark): void
    {
        VoicebotSyncState::query()->updateOrCreate(
            ['entity_kind' => $entityKind],
            ['watermark' => $watermark],
        );
    }

    public function markFullCompleted(string $entityKind, CarbonInterface $at): void
    {
        VoicebotSyncState::query()->updateOrCreate(
            ['entity_kind' => $entityKind],
            ['last_full_at' => $at, 'watermark' => $at],
        );
    }
}

<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;

/**
 * A producer of one entity kind. The default config-driven implementation covers
 * the common case; bind your own implementation in a service provider for full
 * control over how your models map to the canonical schema.
 */
interface EntitySource
{
    public function kind(): EntityKind;

    /**
     * Upsert records. When $since is null, ALL records (full snapshot); otherwise
     * only records changed strictly after $since. MUST stream (never materialise
     * the whole result set).
     *
     * @return LazyCollection<int, CanonicalEntity>
     */
    public function upserts(?CarbonInterface $since): LazyCollection;

    /**
     * Delete operations since $since (e.g. soft-deleted rows). May be empty when
     * the source cannot detect deletes — the nightly full snapshot reconciles
     * hard deletes via server-side tombstones.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function deletes(?CarbonInterface $since): LazyCollection;

    /** Total current record count — used to arm the server tombstone guard on full snapshots. */
    public function expectedCount(): int;

    /** Column compared against the watermark for delta selection. */
    public function updatedAtColumn(): string;
}

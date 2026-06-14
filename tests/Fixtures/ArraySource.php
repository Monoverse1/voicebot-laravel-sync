<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Tests\Fixtures;

use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;

/**
 * In-memory EntitySource for sync-engine tests — no DB, deterministic streams.
 */
final class ArraySource implements EntitySource
{
    /**
     * @param  list<CanonicalEntity>  $upserts
     * @param  list<array<string, mixed>>  $deletes
     */
    public function __construct(
        private readonly EntityKind $kind,
        private readonly array $upserts = [],
        private readonly array $deletes = [],
    ) {}

    public function kind(): EntityKind
    {
        return $this->kind;
    }

    public function upserts(?CarbonInterface $since): LazyCollection
    {
        return LazyCollection::make($this->upserts);
    }

    public function deletes(?CarbonInterface $since): LazyCollection
    {
        return LazyCollection::make($this->deletes);
    }

    public function expectedCount(): int
    {
        return count($this->upserts);
    }

    public function updatedAtColumn(): string
    {
        return 'updated_at';
    }
}

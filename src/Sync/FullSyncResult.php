<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sync;

final readonly class FullSyncResult
{
    /** @param array<string, int> $counts */
    public function __construct(
        public array $counts,
        public int $total,
        public ?string $syncId,
        public bool $dryRun,
    ) {}
}

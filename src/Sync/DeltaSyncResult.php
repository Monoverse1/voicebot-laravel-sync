<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sync;

final readonly class DeltaSyncResult
{
    public function __construct(
        public int $processed,
        public int $batches,
        public int $deadLettered,
        public bool $dryRun,
    ) {}
}

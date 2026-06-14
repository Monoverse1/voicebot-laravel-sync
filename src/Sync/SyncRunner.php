<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Sources\SourceResolver;

/**
 * Single entry point shared by the console command and the queue job: resolves the
 * active entity sources from config and drives a full snapshot or a delta. Keeps
 * source-building and run orchestration in one place so command and job never drift.
 */
final class SyncRunner
{
    public function __construct(
        private readonly SourceResolver $resolver,
        private readonly FullSync $fullSync,
        private readonly DeltaSync $deltaSync,
        private readonly Config $config,
    ) {}

    public function full(bool $dryRun = false): FullSyncResult
    {
        return $this->fullSync->run($this->sources(), $dryRun);
    }

    public function delta(bool $dryRun = false, ?CarbonImmutable $since = null): DeltaSyncResult
    {
        return $this->deltaSync->run($this->sources(), $dryRun, $since);
    }

    /** @return list<EntitySource> */
    public function sources(): array
    {
        /** @var array<string, mixed> $entities */
        $entities = $this->config->get('voicebot.entities', []);
        $chunkSizeRaw = $this->config->get('voicebot.sync.chunk_size', 200);
        $chunkSize = is_numeric($chunkSizeRaw) ? (int) $chunkSizeRaw : 200;

        return $this->resolver->resolve($entities, $chunkSize);
    }
}

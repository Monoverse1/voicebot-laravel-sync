<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Protocol\Protocol;
use Monoverse\VoicebotSync\Support\Watermark;

/**
 * Full snapshot: stream gzip-NDJSON -> init (with exact expected_counts) -> PUT ->
 * finalize. The watermark is advanced to the snapshot start so subsequent deltas
 * pick up only later changes.
 */
final class FullSync
{
    public function __construct(
        private readonly IngestClient $client,
        private readonly NdjsonStreamWriter $writer,
        private readonly Watermark $watermark,
    ) {}

    /** @param list<EntitySource> $sources */
    public function run(array $sources, bool $dryRun = false): FullSyncResult
    {
        $startedAt = CarbonImmutable::now();
        $snapshot = $this->writer->writeSnapshot($sources);

        try {
            if ($snapshot['total'] === 0) {
                // A zero-row full snapshot would arm the tombstone guard to wipe the
                // catalog. Refuse — almost always a mapping/config error. Use a delta
                // or fix the mapping; an intentional purge is a separate operation.
                throw new ConfigException(
                    'refusing to push an empty full snapshot (this would delete the catalog); check your config map or run with --dry-run',
                );
            }

            if ($dryRun) {
                return new FullSyncResult($snapshot['counts'], $snapshot['total'], null, true);
            }

            $size = (int) (@filesize($snapshot['path']) ?: 0);
            if ($size > Protocol::MAX_FULL_BYTES) {
                throw new ConfigException('snapshot exceeds the 200MB full-upload limit; sync in smaller scopes');
            }

            $init = $this->client->init('full', $snapshot['counts']);
            $this->client->uploadFile($init['upload_url'], $snapshot['path']);
            $this->client->finalize($init['sync_id'], (string) Str::uuid());

            foreach ($sources as $source) {
                $this->watermark->markFullCompleted($source->kind()->value, $startedAt);
            }

            return new FullSyncResult($snapshot['counts'], $snapshot['total'], $init['sync_id'], false);
        } finally {
            @unlink($snapshot['path']);
        }
    }
}

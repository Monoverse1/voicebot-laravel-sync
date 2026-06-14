<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sync;

use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Exceptions\ConfigException;

/**
 * Streams canonical entities to a gzipped NDJSON temp file. Pulls from each
 * source's LazyCollection, so the full catalog is never held in memory; counts
 * per kind as it writes so the caller can arm the server tombstone guard with
 * exactly what was sent.
 */
final class NdjsonStreamWriter
{
    /**
     * @param  list<EntitySource>  $sources
     * @return array{path: string, counts: array<string, int>, total: int}
     */
    public function writeSnapshot(array $sources): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'vbsync_');
        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new ConfigException('could not open temp file for snapshot');
        }

        $counts = [];
        $total = 0;
        try {
            foreach ($sources as $source) {
                $kind = $source->kind()->value;
                foreach ($source->upserts(null) as $entity) {
                    $this->writeLine($handle, $entity);
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                    $total++;
                }
            }
        } finally {
            gzclose($handle);
        }

        return ['path' => $path, 'counts' => $counts, 'total' => $total];
    }

    /** @param resource $handle */
    private function writeLine($handle, CanonicalEntity $entity): void
    {
        $line = json_encode(
            $entity->toNdjsonRecord(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        gzwrite($handle, $line."\n");
    }
}

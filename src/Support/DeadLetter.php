<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Support;

use Monoverse\VoicebotSync\Models\VoicebotDeadLetter;

/**
 * Parks delta operations that failed to push so they are never silently dropped.
 * Re-failures of the same op identity (entity_kind + external_id + op_type) bump an
 * attempt counter instead of duplicating rows; once attempts reach the configured
 * ceiling the row is flagged `exhausted` for manual inspection/replay.
 */
final class DeadLetter
{
    public function __construct(private readonly int $maxAttempts = 5) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return int number of ops parked or re-parked this call (for the run summary)
     */
    public function record(string $batchId, array $operations, string $error): int
    {
        $count = 0;
        foreach ($operations as $op) {
            $entityKind = self::str($op['entity_kind'] ?? null, 'unknown');
            $externalIdRaw = $op['external_id'] ?? null;
            $externalId = is_scalar($externalIdRaw) ? (string) $externalIdRaw : null;
            $opType = self::str($op['type'] ?? null, 'upsert');

            $existing = VoicebotDeadLetter::query()
                ->where('entity_kind', $entityKind)
                ->where('external_id', $externalId)
                ->where('op_type', $opType)
                ->first();

            if ($existing === null) {
                VoicebotDeadLetter::query()->create([
                    'batch_id' => $batchId,
                    'entity_kind' => $entityKind,
                    'external_id' => $externalId,
                    'op_type' => $opType,
                    'payload' => $op['payload'] ?? null,
                    'error' => $error,
                    'attempts' => 1,
                    'exhausted' => $this->maxAttempts <= 1,
                    'failed_at' => now(),
                ]);
                $count++;

                continue;
            }

            $attempts = $existing->attempts + 1;
            $existing->forceFill([
                'batch_id' => $batchId,
                'payload' => $op['payload'] ?? $existing->payload,
                'error' => $error,
                'attempts' => $attempts,
                'exhausted' => $attempts >= $this->maxAttempts,
                'failed_at' => now(),
            ])->save();
            $count++;
        }

        return $count;
    }

    private static function str(mixed $value, string $default): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}

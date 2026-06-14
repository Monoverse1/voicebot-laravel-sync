<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Dto;

/**
 * One canonical record on the wire. Snapshots use the NDJSON record shape
 * ({kind, external_id, lang?, translation_of?, payload}); deltas use the
 * /events operation shape ({type, entity_kind, external_id, ..., payload}).
 */
final readonly class CanonicalEntity
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public EntityKind $kind,
        public string $externalId,
        public array $payload,
        public ?string $lang = null,
        public ?string $translationOf = null,
    ) {}

    /** @return array<string, mixed> */
    public function toNdjsonRecord(): array
    {
        $record = [
            'kind' => $this->kind->value,
            'external_id' => $this->externalId,
            'payload' => $this->payload,
        ];
        if ($this->lang !== null) {
            $record['lang'] = $this->lang;
        }
        if ($this->translationOf !== null) {
            $record['translation_of'] = $this->translationOf;
        }

        return $record;
    }

    /** @return array<string, mixed> */
    public function toUpsertOperation(): array
    {
        $op = [
            'type' => 'upsert',
            'entity_kind' => $this->kind->value,
            'external_id' => $this->externalId,
            'payload' => $this->payload,
        ];
        if ($this->lang !== null) {
            $op['lang'] = $this->lang;
        }
        if ($this->translationOf !== null) {
            $op['translation_of'] = $this->translationOf;
        }

        return $op;
    }

    /** @return array<string, mixed> */
    public static function deleteOperation(EntityKind $kind, string $externalId): array
    {
        return [
            'type' => 'delete',
            'entity_kind' => $kind->value,
            'external_id' => $externalId,
        ];
    }
}

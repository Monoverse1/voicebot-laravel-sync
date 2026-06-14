<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Mapping;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;

/**
 * Turns one Eloquent model into a CanonicalEntity using a config map. Map keys are
 * dot paths under `payload` (e.g. 'payload.name'); values are either a column/dot
 * path (string, resolved via data_get) or a closure receiving the model. The raw
 * external id is wrapped to "laravel:{kind}:{id}".
 */
final class EntityMapper
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly EntityKind $kind,
        private readonly array $config,
    ) {}

    public function map(Model $model): CanonicalEntity
    {
        $rawId = $this->resolve($this->config['external_id'] ?? 'id', $model);
        $externalId = sprintf('laravel:%s:%s', $this->kind->value, $this->scalarString($rawId));

        /** @var array<string, mixed> $payload */
        $payload = [];
        /** @var array<string, mixed> $map */
        $map = $this->config['map'] ?? [];
        foreach ($map as $key => $spec) {
            $path = str_starts_with($key, 'payload.') ? substr($key, 8) : $key;
            Arr::set($payload, $path, $this->resolve($spec, $model));
        }

        $lang = isset($this->config['lang']) ? $this->stringOrNull($this->resolve($this->config['lang'], $model)) : null;
        $translationOf = isset($this->config['translation_of'])
            ? $this->stringOrNull($this->resolve($this->config['translation_of'], $model))
            : null;

        /** @var array<string, mixed> $payload */
        return new CanonicalEntity($this->kind, $externalId, $payload, $lang, $translationOf);
    }

    private function resolve(mixed $spec, Model $model): mixed
    {
        if ($spec instanceof Closure) {
            return $spec($model);
        }
        if (is_string($spec)) {
            return data_get($model, $spec);
        }

        return $spec;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : $this->scalarString($value);
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

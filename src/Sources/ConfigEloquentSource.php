<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sources;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Mapping\EntityMapper;

/**
 * Default, config-driven source: reads an Eloquent model and maps each row via
 * EntityMapper. Streams with lazyById so the full catalog never lands in memory. A
 * single row may fan out to several canonical entities (a multilingual base + its
 * translations). Detects deletes only when the model uses SoftDeletes; hard deletes
 * are reconciled by the nightly full snapshot's server-side tombstone pass.
 */
final class ConfigEloquentSource implements EntitySource
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly EntityKind $kind,
        private readonly array $config,
        private readonly EntityMapper $mapper,
        private readonly int $chunkSize = 200,
    ) {}

    public function kind(): EntityKind
    {
        return $this->kind;
    }

    public function upserts(?CarbonInterface $since): LazyCollection
    {
        $query = $this->modelClass()::query();
        $with = $this->relationsToEagerLoad();
        if ($with !== []) {
            $query->with($with);
        }
        if ($since !== null) {
            $query->where($this->updatedAtColumn(), '>', $since);
        }

        return $query->lazyById($this->chunkSize)->flatMap(
            fn (Model $model): array => $this->mapper->mapRows($model),
        );
    }

    public function deletes(?CarbonInterface $since): LazyCollection
    {
        $class = $this->modelClass();
        if ($since === null || ! in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            return LazyCollection::empty();
        }

        // onlyTrashed() is provided by the SoftDeletes trait (verified above) but is not
        // on the generic Builder<Model> type, so PHPStan cannot see it here.
        /** @var Builder<Model> $query */
        $query = $class::onlyTrashed(); // @phpstan-ignore staticMethod.notFound
        $query->where('deleted_at', '>', $since);

        return $query->lazyById($this->chunkSize)->flatMap(
            fn (Model $model): array => array_map(
                fn (string $externalId): array => CanonicalEntity::deleteOperation($this->kind, $externalId),
                $this->mapper->externalIds($model),
            ),
        );
    }

    /** @return list<string> */
    private function relationsToEagerLoad(): array
    {
        $with = $this->config['with'] ?? [];
        if (! is_array($with)) {
            return [];
        }
        $relations = [];
        foreach ($with as $relation) {
            if (is_string($relation) && $relation !== '') {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    public function expectedCount(): int
    {
        return $this->modelClass()::query()->count();
    }

    public function updatedAtColumn(): string
    {
        $column = $this->config['updated_at'] ?? 'updated_at';

        return is_string($column) ? $column : 'updated_at';
    }

    /** @return class-string<Model> */
    private function modelClass(): string
    {
        $model = $this->config['model'] ?? null;
        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw new ConfigException(sprintf(
                'voicebot.entities.%s.model must be a valid Eloquent model class (got %s). Set it in config/voicebot.php or bind a custom source.',
                $this->kind->value,
                is_string($model) ? $model : gettype($model),
            ));
        }

        return $model;
    }
}

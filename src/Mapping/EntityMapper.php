<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Mapping;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;

/**
 * Turns one Eloquent model into one or more CanonicalEntity rows using a config map.
 * Map keys are dot paths under `payload` (e.g. 'payload.name'); values are a column /
 * dot path (string, resolved via data_get) or a closure receiving ($model, $locale).
 * The raw external id is wrapped to "laravel:{kind}:{id}".
 *
 * With a `translations` block ({ locales, base_locale, present? }) a single row fans
 * out to a base entity plus one per extra locale. A `variations` block builds the
 * canonical variant_axes + inline variations[] from a relation — no custom source.
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
        return $this->mapRows($model)[0];
    }

    /** @return non-empty-list<CanonicalEntity> */
    public function mapRows(Model $model): array
    {
        $baseExternalId = $this->baseExternalId($model);
        $locales = $this->configuredLocales();

        if ($locales === null) {
            $lang = isset($this->config['lang'])
                ? $this->stringOrNull($this->resolve($this->config['lang'], $model))
                : null;
            $translationOf = isset($this->config['translation_of'])
                ? $this->stringOrNull($this->resolve($this->config['translation_of'], $model))
                : null;

            return [new CanonicalEntity($this->kind, $baseExternalId, $this->payload($model, null), $lang, $translationOf)];
        }

        $baseLocale = $this->baseLocale($locales);
        $rows = [];
        foreach ($locales as $locale) {
            if (! $this->localePresent($model, $locale)) {
                continue;
            }
            $isBase = $locale === $baseLocale;
            $rows[] = new CanonicalEntity(
                $this->kind,
                $isBase ? $baseExternalId : $baseExternalId.':'.$locale,
                $this->payload($model, $locale),
                $locale,
                $isBase ? null : $baseExternalId,
            );
        }

        if ($rows === []) {
            return [new CanonicalEntity($this->kind, $baseExternalId, $this->payload($model, $baseLocale), $baseLocale, null)];
        }

        return $rows;
    }

    /** @return non-empty-list<string> */
    public function externalIds(Model $model): array
    {
        $baseExternalId = $this->baseExternalId($model);
        $locales = $this->configuredLocales();
        if ($locales === null) {
            return [$baseExternalId];
        }

        $baseLocale = $this->baseLocale($locales);
        $ids = [];
        foreach ($locales as $locale) {
            if (! $this->localePresent($model, $locale)) {
                continue;
            }
            $ids[] = $locale === $baseLocale ? $baseExternalId : $baseExternalId.':'.$locale;
        }

        return $ids === [] ? [$baseExternalId] : $ids;
    }

    private function baseExternalId(Model $model): string
    {
        $rawId = $this->scalarString($this->resolve($this->config['external_id'] ?? 'id', $model));

        return ExternalId::format('laravel', $this->kind->value, $rawId);
    }

    /** @return array<string, mixed> */
    private function payload(Model $model, ?string $locale): array
    {
        $payload = [];
        $map = $this->config['map'] ?? [];
        if (is_array($map)) {
            foreach ($map as $key => $spec) {
                $path = str_starts_with((string) $key, 'payload.') ? substr((string) $key, 8) : (string) $key;
                Arr::set($payload, $path, $this->resolve($spec, $model, $locale));
            }
        }

        $variationsConfig = $this->config['variations'] ?? null;
        if (is_array($variationsConfig)) {
            [$axes, $variations] = $this->buildVariations($model, $variationsConfig, $locale);
            if ($axes !== []) {
                $payload['variant_axes'] = $axes;
            }
            if ($variations !== []) {
                $payload['variations'] = $variations;
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildVariations(Model $model, array $config, ?string $locale): array
    {
        $items = $this->variationItems($model, $config);
        if ($items === []) {
            return [[], []];
        }

        $axesConfig = is_array($config['axes'] ?? null) ? $config['axes'] : [];

        $variations = [];
        /** @var array<string, array<string, mixed>> $axesAccum */
        $axesAccum = [];

        foreach ($items as $variant) {
            $attributes = [];
            foreach ($axesConfig as $axisSlug => $axisSpec) {
                if (! is_array($axisSpec)) {
                    continue;
                }
                $axisName = $this->axisName($axisSpec['name'] ?? (string) $axisSlug, $locale);
                $valueLabel = $this->stringOrNull($this->resolve($axisSpec['value'] ?? null, $variant, $locale));
                if ($valueLabel === null || $valueLabel === '') {
                    continue;
                }
                $attributes[$axisName] = $valueLabel;
                $this->accumulateAxisValue($axesAccum, (string) $axisSlug, $axisName, $valueLabel, $axisSpec, $variant, $locale);
            }

            $variation = [
                'external_id' => $this->scalarString($this->resolve($config['external_id'] ?? 'id', $variant, $locale)),
                'attributes' => $attributes,
            ];
            $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
            foreach ($fields as $fieldKey => $fieldSpec) {
                $variation[(string) $fieldKey] = $this->resolve($fieldSpec, $variant, $locale);
            }
            $variations[] = $variation;
        }

        $axes = [];
        foreach ($axesAccum as $axis) {
            /** @var array<string, array<string, mixed>> $values */
            $values = is_array($axis['values'] ?? null) ? $axis['values'] : [];
            $axes[] = [
                'name' => $axis['name'],
                'slug' => $axis['slug'],
                'values' => array_values($values),
            ];
        }

        return [$axes, $variations];
    }

    /**
     * @param  array<string, array<string, mixed>>  $axesAccum
     * @param  array<array-key, mixed>  $axisSpec
     */
    private function accumulateAxisValue(array &$axesAccum, string $axisSlug, string $axisName, string $valueLabel, array $axisSpec, Model $variant, ?string $locale): void
    {
        if (! isset($axesAccum[$axisSlug])) {
            $axesAccum[$axisSlug] = ['name' => $axisName, 'slug' => $axisSlug, 'values' => []];
        }
        $axesAccum[$axisSlug]['name'] = $axisName;

        /** @var array<string, array<string, mixed>> $values */
        $values = $axesAccum[$axisSlug]['values'];
        if (isset($values[$valueLabel])) {
            return;
        }

        $value = ['label' => $valueLabel];
        foreach (['value_slug' => 'slug', 'value_external_id' => 'external_id'] as $specKey => $outKey) {
            if (isset($axisSpec[$specKey])) {
                $resolved = $this->stringOrNull($this->resolve($axisSpec[$specKey], $variant, $locale));
                if ($resolved !== null && $resolved !== '') {
                    $value[$outKey] = $resolved;
                }
            }
        }
        $values[$valueLabel] = $value;
        $axesAccum[$axisSlug]['values'] = $values;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return list<Model>
     */
    private function variationItems(Model $model, array $config): array
    {
        $items = $this->resolve($config['items'] ?? null, $model);
        if ($items instanceof Collection) {
            $items = $items->all();
        }
        if (! is_iterable($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if ($item instanceof Model) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function axisName(mixed $spec, ?string $locale): string
    {
        return $spec instanceof Closure ? $this->scalarString($spec($locale)) : $this->scalarString($spec);
    }

    /** @return non-empty-list<string>|null */
    private function configuredLocales(): ?array
    {
        $translations = $this->config['translations'] ?? null;
        if (! is_array($translations)) {
            return null;
        }
        $locales = $translations['locales'] ?? null;
        if (! is_array($locales)) {
            return null;
        }
        $out = [];
        foreach ($locales as $locale) {
            if (is_string($locale) && $locale !== '') {
                $out[] = $locale;
            }
        }

        return $out === [] ? null : $out;
    }

    /** @param non-empty-list<string> $locales */
    private function baseLocale(array $locales): string
    {
        $translations = $this->config['translations'] ?? [];
        $base = is_array($translations) ? ($translations['base_locale'] ?? null) : null;

        return is_string($base) && $base !== '' ? $base : $locales[0];
    }

    private function localePresent(Model $model, string $locale): bool
    {
        $translations = $this->config['translations'] ?? [];
        $present = is_array($translations) ? ($translations['present'] ?? null) : null;

        return $present instanceof Closure ? (bool) $present($model, $locale) : true;
    }

    private function resolve(mixed $spec, Model $model, ?string $locale = null): mixed
    {
        if ($spec instanceof Closure) {
            return $spec($model, $locale);
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

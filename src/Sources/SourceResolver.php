<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sources;

use Illuminate\Contracts\Container\Container;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Mapping\EntityMapper;

/**
 * Builds the list of active entity sources from config. A kind may point at a
 * container-bound custom source (`source` = class implementing EntitySource);
 * otherwise the default config-driven Eloquent source is used.
 */
final class SourceResolver
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, mixed>  $entitiesConfig
     * @return list<EntitySource>
     */
    public function resolve(array $entitiesConfig, int $chunkSize): array
    {
        $derivedHostCapabilities = $this->derivedHostCapabilities($entitiesConfig);

        $sources = [];
        $hasHostProfile = false;
        foreach ($entitiesConfig as $kindValue => $config) {
            if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
                continue;
            }
            $kind = EntityKind::tryFrom((string) $kindValue);
            if ($kind === null) {
                continue;
            }
            /** @var array<string, mixed> $config */
            $sources[] = $this->buildSource($kind, $config, $chunkSize, $derivedHostCapabilities);
            if ($kind === EntityKind::HostProfile) {
                $hasHostProfile = true;
            }
        }

        if (! $hasHostProfile && $derivedHostCapabilities !== []) {
            $sources[] = HostProfileSource::fromConfig([], $derivedHostCapabilities);
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $derivedHostCapabilities
     */
    private function buildSource(EntityKind $kind, array $config, int $chunkSize, array $derivedHostCapabilities): EntitySource
    {
        $custom = $config['source'] ?? null;
        if (is_string($custom) && $custom !== '') {
            $instance = $this->container->make($custom);
            if (! $instance instanceof EntitySource) {
                throw new ConfigException(sprintf(
                    'voicebot.entities.%s.source (%s) must implement %s.',
                    $kind->value,
                    $custom,
                    EntitySource::class,
                ));
            }

            return $instance;
        }

        if ($kind === EntityKind::HostProfile) {
            return HostProfileSource::fromConfig($config, $derivedHostCapabilities);
        }

        return new ConfigEloquentSource($kind, $config, new EntityMapper($kind, $config), $chunkSize);
    }

    /**
     * Capabilities inferred from the catalog config, not declared on host_profile. The bot
     * may only claim attribute filtering (catalog.facets gates apply_filter /
     * filter_by_attribute) when products actually push a structured `attributes` map or a
     * `variations` block — otherwise the facet index is empty and the bot would lie.
     *
     * @param  array<string, mixed>  $entitiesConfig
     * @return list<string>
     */
    private function derivedHostCapabilities(array $entitiesConfig): array
    {
        $product = $entitiesConfig[EntityKind::Product->value] ?? null;
        if (! is_array($product) || ($product['enabled'] ?? false) !== true) {
            return [];
        }

        return $this->productHasFacetConfig($product) ? [HostCapability::CatalogFacets->value] : [];
    }

    /** @param array<array-key, mixed> $product */
    private function productHasFacetConfig(array $product): bool
    {
        $map = $product['map'] ?? [];
        if (is_array($map) && (isset($map['payload.attributes']) || isset($map['attributes']))) {
            return true;
        }

        $variations = $product['variations'] ?? null;

        return is_array($variations) && ($variations['axes'] ?? null) !== null;
    }
}

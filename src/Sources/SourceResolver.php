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
        $sources = [];
        foreach ($entitiesConfig as $kindValue => $config) {
            if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
                continue;
            }
            $kind = EntityKind::tryFrom((string) $kindValue);
            if ($kind === null) {
                continue;
            }
            /** @var array<string, mixed> $config */
            $sources[] = $this->buildSource($kind, $config, $chunkSize);
        }

        return $sources;
    }

    /** @param array<string, mixed> $config */
    private function buildSource(EntityKind $kind, array $config, int $chunkSize): EntitySource
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

        return new ConfigEloquentSource($kind, $config, new EntityMapper($kind, $config), $chunkSize);
    }
}

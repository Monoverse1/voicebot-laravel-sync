<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Sources;

use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;
use Monoverse\VoicebotSync\Contracts\EntitySource;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Exceptions\ConfigException;

final class HostProfileSource implements EntitySource
{
    /**
     * @param  list<string>  $capabilities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly ?string $cartEndpoint,
        private readonly array $metadata,
    ) {
        $this->assertCapabilitiesValid($this->capabilities);
    }

    /**
     * @param  array<string, mixed>  $config  voicebot.entities.host_profile
     * @param  list<string>  $derivedCapabilities  capabilities inferred from sibling entity config (e.g. catalog.facets when products push structured attributes)
     */
    public static function fromConfig(array $config, array $derivedCapabilities = []): self
    {
        $raw = $config['capabilities'] ?? [];
        if (! is_array($raw)) {
            throw new ConfigException(
                'voicebot.entities.host_profile.capabilities must be an array of capability strings.',
            );
        }

        /** @var list<string> $declared */
        $declared = array_values(array_filter(
            $raw,
            static fn (mixed $v): bool => is_string($v),
        ));

        $capabilities = self::mergeCapabilities($declared, $derivedCapabilities);

        $cartEndpoint = $config['cart_endpoint'] ?? null;
        if ($cartEndpoint !== null && ! is_string($cartEndpoint)) {
            throw new ConfigException(
                'voicebot.entities.host_profile.cart_endpoint must be a string or null.',
            );
        }

        $metadata = $config['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new ConfigException(
                'voicebot.entities.host_profile.metadata must be an associative array.',
            );
        }

        /** @var array<string, mixed> $metadata */
        return new self($capabilities, is_string($cartEndpoint) ? $cartEndpoint : null, $metadata);
    }

    public function kind(): EntityKind
    {
        return EntityKind::HostProfile;
    }

    public function upserts(?CarbonInterface $since): LazyCollection
    {
        return LazyCollection::make(function (): \Generator {
            yield new CanonicalEntity(
                EntityKind::HostProfile,
                'host',
                $this->buildPayload(),
            );
        });
    }

    public function deletes(?CarbonInterface $since): LazyCollection
    {
        return LazyCollection::empty();
    }

    public function expectedCount(): int
    {
        return 1;
    }

    public function updatedAtColumn(): string
    {
        return 'updated_at';
    }

    /** @return array<string, mixed> */
    private function buildPayload(): array
    {
        $payload = ['capabilities' => $this->capabilities];

        if ($this->cartEndpoint !== null) {
            $payload['cart_endpoint'] = $this->cartEndpoint;
        }

        if ($this->metadata !== []) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $derived
     * @return list<string>
     */
    private static function mergeCapabilities(array $declared, array $derived): array
    {
        $merged = $declared;
        foreach ($derived as $capability) {
            if (! in_array($capability, $merged, true)) {
                $merged[] = $capability;
            }
        }

        return $merged;
    }

    /** @param list<string> $capabilities */
    private function assertCapabilitiesValid(array $capabilities): void
    {
        $unknown = [];
        foreach ($capabilities as $value) {
            if (HostCapability::tryFrom($value) === null) {
                $unknown[] = $value;
            }
        }

        if ($unknown !== []) {
            throw new ConfigException(sprintf(
                'voicebot.entities.host_profile.capabilities contains unknown value(s): %s. '
                .'Valid values: %s.',
                implode(', ', $unknown),
                implode(', ', array_column(HostCapability::cases(), 'value')),
            ));
        }
    }
}

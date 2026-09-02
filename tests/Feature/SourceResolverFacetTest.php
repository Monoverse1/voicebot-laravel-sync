<?php

declare(strict_types=1);

use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Sources\HostProfileSource;
use Monoverse\VoicebotSync\Sources\SourceResolver;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

/** @return array<string, mixed> */
function productEntity(array $extra = []): array
{
    return array_merge([
        'enabled' => true,
        'source' => null,
        'model' => FakeProduct::class,
        'external_id' => 'id',
        'updated_at' => 'updated_at',
        'with' => [],
        'map' => ['payload.name' => 'title'],
    ], $extra);
}

function resolveSources(array $entities): array
{
    $resolver = new SourceResolver(app());

    return $resolver->resolve($entities, 200);
}

function hostProfileCapabilities(array $sources): ?array
{
    foreach ($sources as $source) {
        if ($source instanceof HostProfileSource) {
            /** @var CanonicalEntity $entity */
            $entity = $source->upserts(null)->first();

            return $entity->payload['capabilities'];
        }
    }

    return null;
}

it('auto-advertises catalog.facets when products declare a structured attributes map', function (): void {
    $sources = resolveSources([
        EntityKind::Product->value => productEntity([
            'map' => ['payload.name' => 'title', 'payload.attributes' => fn () => []],
        ]),
    ]);

    expect(hostProfileCapabilities($sources))->toBe(['catalog.facets']);
});

it('auto-advertises catalog.facets when products declare a variations block with axes', function (): void {
    $sources = resolveSources([
        EntityKind::Product->value => productEntity([
            'variations' => [
                'items' => fn () => [],
                'external_id' => 'id',
                'axes' => ['color' => ['name' => 'Колір', 'value' => 'color']],
            ],
        ]),
    ]);

    expect(hostProfileCapabilities($sources))->toBe(['catalog.facets']);
});

it('does NOT advertise catalog.facets when products push no structured attributes', function (): void {
    $sources = resolveSources([
        EntityKind::Product->value => productEntity(),
    ]);

    expect(hostProfileCapabilities($sources))->toBeNull();
});

it('merges derived catalog.facets into an explicitly enabled host_profile without duplicating', function (): void {
    $sources = resolveSources([
        EntityKind::Product->value => productEntity([
            'map' => ['payload.name' => 'title', 'payload.attributes' => fn () => []],
        ]),
        EntityKind::HostProfile->value => [
            'enabled' => true,
            'source' => null,
            'capabilities' => ['cart.add', 'catalog.facets'],
        ],
    ]);

    expect(hostProfileCapabilities($sources))->toBe(['cart.add', 'catalog.facets']);
});

it('appends derived catalog.facets to an enabled host_profile that omitted it', function (): void {
    $sources = resolveSources([
        EntityKind::Product->value => productEntity([
            'map' => ['payload.name' => 'title', 'payload.attributes' => fn () => []],
        ]),
        EntityKind::HostProfile->value => [
            'enabled' => true,
            'source' => null,
            'capabilities' => ['cart.add'],
        ],
    ]);

    expect(hostProfileCapabilities($sources))->toBe(['cart.add', 'catalog.facets']);
});

it('does not synthesize a host_profile when products carry no facets', function (): void {
    $sources = resolveSources([
        EntityKind::Product->value => productEntity(),
    ]);

    $hasHostProfile = false;
    foreach ($sources as $source) {
        if ($source instanceof HostProfileSource) {
            $hasHostProfile = true;
        }
    }

    expect($hasHostProfile)->toBeFalse();
});

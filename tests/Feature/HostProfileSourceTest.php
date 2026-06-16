<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Exceptions\ConfigException;
use Monoverse\VoicebotSync\Sources\HostCapability;
use Monoverse\VoicebotSync\Sources\HostProfileSource;

it('emits a single host_profile entity with the configured capabilities', function (): void {
    $source = HostProfileSource::fromConfig([
        'capabilities' => ['cart.add', 'cart.view', 'variant.select'],
    ]);

    expect($source->kind())->toBe(EntityKind::HostProfile)
        ->and($source->expectedCount())->toBe(1);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity)->toBeInstanceOf(CanonicalEntity::class)
        ->and($entity->kind)->toBe(EntityKind::HostProfile)
        ->and($entity->externalId)->toBe('host')
        ->and($entity->payload['capabilities'])->toBe(['cart.add', 'cart.view', 'variant.select']);
});

it('includes cart_endpoint in the payload when configured', function (): void {
    $source = HostProfileSource::fromConfig([
        'capabilities' => ['cart.add'],
        'cart_endpoint' => '/api/cart',
    ]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity->payload['cart_endpoint'])->toBe('/api/cart');
});

it('omits cart_endpoint from the payload when null', function (): void {
    $source = HostProfileSource::fromConfig([
        'capabilities' => ['cart.add'],
        'cart_endpoint' => null,
    ]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity->payload)->not->toHaveKey('cart_endpoint');
});

it('includes metadata in the payload when non-empty', function (): void {
    $source = HostProfileSource::fromConfig([
        'capabilities' => ['cart.add'],
        'metadata' => ['theme' => 'custom'],
    ]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity->payload['metadata'])->toBe(['theme' => 'custom']);
});

it('omits metadata from the payload when empty', function (): void {
    $source = HostProfileSource::fromConfig([
        'capabilities' => ['cart.add'],
        'metadata' => [],
    ]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity->payload)->not->toHaveKey('metadata');
});

it('emits no deletes regardless of the watermark', function (): void {
    $source = HostProfileSource::fromConfig(['capabilities' => ['cart.add']]);

    expect($source->deletes(null)->all())->toBe([])
        ->and($source->deletes(CarbonImmutable::now())->all())->toBe([]);
});

it('throws ConfigException on an unknown capability value', function (): void {
    HostProfileSource::fromConfig([
        'capabilities' => ['cart.add', 'unknown.capability'],
    ]);
})->throws(ConfigException::class, 'unknown.capability');

it('throws ConfigException when capabilities contains a non-string entry and it slips past filter', function (): void {
    HostProfileSource::fromConfig([
        'capabilities' => ['invalid_value'],
    ]);
})->throws(ConfigException::class);

it('accepts an empty capabilities list without error', function (): void {
    $source = HostProfileSource::fromConfig(['capabilities' => []]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity->payload['capabilities'])->toBe([]);
});

it('covers every HostCapability value in the closed vocabulary', function (): void {
    $allValues = array_column(HostCapability::cases(), 'value');

    $source = HostProfileSource::fromConfig(['capabilities' => $allValues]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();

    expect($entity->payload['capabilities'])->toBe($allValues);
});

it('produces a valid ndjson record shape', function (): void {
    $source = HostProfileSource::fromConfig(['capabilities' => ['cart.add']]);

    /** @var CanonicalEntity $entity */
    $entity = $source->upserts(null)->first();
    $record = $entity->toNdjsonRecord();

    expect($record['kind'])->toBe('host_profile')
        ->and($record['external_id'])->toBe('host')
        ->and($record['payload'])->toBeArray();
});

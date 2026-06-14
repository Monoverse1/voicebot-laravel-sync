<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Mapping\EntityMapper;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('sku')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
});

it('wraps the external id, maps closures (money minor units) and string columns', function (): void {
    $product = FakeProduct::query()->create(['title' => 'Espresso', 'sku' => 'ESP-1', 'price' => 199.99]);

    $mapper = new EntityMapper(EntityKind::Product, [
        'external_id' => 'id',
        'map' => [
            'payload.name' => 'title',
            'payload.sku' => 'sku',
            'payload.price_amount' => fn (FakeProduct $m): int => (int) round($m->price * 100),
            'payload.currency' => fn (): string => 'UAH',
            'payload.categories' => fn (): array => ['kava', 'napoi'],
        ],
    ]);

    $entity = $mapper->map($product);

    expect($entity->externalId)->toBe('laravel:product:'.$product->id)
        ->and($entity->kind)->toBe(EntityKind::Product)
        ->and($entity->payload['name'])->toBe('Espresso')
        ->and($entity->payload['sku'])->toBe('ESP-1')
        ->and($entity->payload['price_amount'])->toBe(19999) // integer minor units
        ->and($entity->payload['currency'])->toBe('UAH')
        ->and($entity->payload['categories'])->toBe(['kava', 'napoi']);
});

it('emits the canonical NDJSON record and upsert operation shapes', function (): void {
    $product = FakeProduct::query()->create(['title' => 'Latte', 'price' => 50]);

    $entity = (new EntityMapper(EntityKind::Product, [
        'external_id' => 'id',
        'map' => ['payload.name' => 'title'],
    ]))->map($product);

    expect($entity->toNdjsonRecord())->toMatchArray([
        'kind' => 'product',
        'external_id' => 'laravel:product:'.$product->id,
        'payload' => ['name' => 'Latte'],
    ]);

    expect($entity->toUpsertOperation())->toMatchArray([
        'type' => 'upsert',
        'entity_kind' => 'product',
        'external_id' => 'laravel:product:'.$product->id,
    ]);
});

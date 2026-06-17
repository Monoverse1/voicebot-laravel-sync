<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Mapping\EntityMapper;
use Monoverse\VoicebotSync\Mapping\Presets;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('sku')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->text('body')->nullable();
        $table->boolean('in_stock')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

function presetPayload(EntityKind $kind, array $map, FakeProduct $model): array
{
    return (new EntityMapper($kind, ['external_id' => 'id', 'map' => $map]))->map($model)->payload;
}

it('product preset builds the canonical payload from a few columns', function (): void {
    $product = FakeProduct::query()->create([
        'title' => 'Espresso',
        'sku' => 'ESP-1',
        'price' => 199.99,
        'body' => '<p>Rich &amp; bold</p>',
        'in_stock' => true,
    ]);

    $map = Presets::product([
        'name' => 'title',
        'sku' => 'sku',
        'price' => 'price',
        'description' => 'body',
        'stock' => 'in_stock',
    ]);

    $payload = presetPayload(EntityKind::Product, $map, $product);

    expect($payload['name'])->toBe('Espresso')
        ->and($payload['sku'])->toBe('ESP-1')
        ->and($payload['price_amount'])->toBe(19999)
        ->and($payload['currency'])->toBe('UAH')
        ->and($payload['description'])->toBe('Rich &amp; bold')
        ->and($payload['stock_status'])->toBe('instock');
});

it('product preset maps falsey stock to outofstock and honours a currency override', function (): void {
    $product = FakeProduct::query()->create(['title' => 'X', 'price' => 5, 'in_stock' => false]);

    $map = Presets::product(['name' => 'title', 'price' => 'price', 'stock' => 'in_stock'], 'EUR');
    $payload = presetPayload(EntityKind::Product, $map, $product);

    expect($payload['price_amount'])->toBe(500)
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['stock_status'])->toBe('outofstock');
});

it('category preset wraps parent_external_id', function (): void {
    $model = FakeProduct::query()->create(['title' => 'Coffee', 'price' => 0]);
    $model->forceFill(['parent_id' => 7]);

    $map = Presets::category(['name' => 'title', 'parent_id' => 'parent_id']);
    $payload = presetPayload(EntityKind::Category, $map, $model);

    expect($payload['name'])->toBe('Coffee')
        ->and($payload['parent_external_id'])->toBe('laravel:category:7');
});

it('category preset returns null parent for a root', function (): void {
    $model = FakeProduct::query()->create(['title' => 'Root', 'price' => 0]);

    $map = Presets::category(['name' => 'title', 'parent_id' => 'parent_id']);
    $payload = presetPayload(EntityKind::Category, $map, $model);

    expect($payload['parent_external_id'])->toBeNull();
});

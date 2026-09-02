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

it('product preset emits a structured attributes facet list from columns', function (): void {
    Schema::table('fake_products', function (Blueprint $table): void {
        $table->string('color_uk')->nullable();
        $table->string('size')->nullable();
    });
    $product = FakeProduct::query()->create([
        'title' => 'Nike Air Force 1',
        'price' => 100,
        'in_stock' => true,
    ]);
    $product->forceFill(['color_uk' => 'Білий', 'size' => '38']);

    $map = Presets::product(
        ['name' => 'title', 'price' => 'price'],
        attributes: ['Колір' => 'color_uk', 'Розмір' => 'size'],
    );
    $payload = presetPayload(EntityKind::Product, $map, $product);

    expect($payload['attributes'])->toBe([
        ['name' => 'Колір', 'slug' => 'колір', 'values' => ['Білий']],
        ['name' => 'Розмір', 'slug' => 'розмір', 'values' => ['38']],
    ]);
});

it('product preset drops empty attribute axes and dedupes values', function (): void {
    Schema::table('fake_products', function (Blueprint $table): void {
        $table->string('color_uk')->nullable();
        $table->string('material')->nullable();
    });
    $product = FakeProduct::query()->create(['title' => 'Tee', 'price' => 10]);
    $product->forceFill(['color_uk' => '', 'material' => 'Cotton']);

    $map = Presets::product(
        ['name' => 'title'],
        attributes: [
            'Колір' => 'color_uk',
            'Матеріал' => fn (object $m): array => ['Cotton', 'Cotton', ' '],
        ],
    );
    $payload = presetPayload(EntityKind::Product, $map, $product);

    expect($payload['attributes'])->toBe([
        ['name' => 'Матеріал', 'slug' => 'матеріал', 'values' => ['Cotton']],
    ]);
});

it('product preset omits attributes entirely when none configured', function (): void {
    $product = FakeProduct::query()->create(['title' => 'Plain', 'price' => 5]);

    $map = Presets::product(['name' => 'title', 'price' => 'price']);
    $payload = presetPayload(EntityKind::Product, $map, $product);

    expect($payload)->not->toHaveKey('attributes');
});

it('variations preset builds the EntityMapper variations block', function (): void {
    $block = Presets::variations(
        items: 'variants',
        externalId: fn (object $v): string => 'laravel:variation:'.$v->id,
        axes: ['color' => ['name' => 'Колір', 'value' => 'color_uk']],
        fields: ['stock_status' => fn (object $v): string => 'instock'],
    );

    expect($block)->toHaveKeys(['items', 'external_id', 'axes', 'fields'])
        ->and($block['items'])->toBe('variants')
        ->and($block['axes'])->toBe(['color' => ['name' => 'Колір', 'value' => 'color_uk']]);
});

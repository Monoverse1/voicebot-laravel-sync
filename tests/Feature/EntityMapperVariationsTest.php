<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Mapping\EntityMapper;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeVariant;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('fake_variants', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->string('color_uk');
        $table->string('color_en')->nullable();
        $table->string('color_hex')->nullable();
        $table->string('size')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->integer('stock_qty')->default(0);
    });
});

/** @return array<string, mixed> */
function variationConfig(): array
{
    return [
        'model' => FakeProduct::class,
        'external_id' => 'id',
        'map' => ['payload.name' => 'title'],
        'variations' => [
            'items' => fn (FakeProduct $p) => FakeVariant::query()->where('product_id', $p->id)->orderBy('id')->get(),
            'external_id' => fn (FakeVariant $v) => 'laravel:variation:'.$v->id,
            'axes' => [
                'color' => [
                    'name' => fn (?string $l) => $l === 'en' ? 'Color' : 'Колір',
                    'value' => fn (FakeVariant $v, ?string $l) => $l === 'en' ? ($v->color_en ?? $v->color_uk) : $v->color_uk,
                    'value_external_id' => fn (FakeVariant $v) => 'laravel:option:color:'.ltrim((string) $v->color_hex, '#'),
                ],
                'size' => [
                    'name' => fn () => 'Розмір',
                    'value' => 'size',
                ],
            ],
            'fields' => [
                'price_amount' => fn (FakeVariant $v) => (int) round($v->price * 100),
                'stock_status' => fn (FakeVariant $v) => $v->stock_qty > 0 ? 'instock' : 'outofstock',
            ],
        ],
    ];
}

it('builds variant_axes and inline variations from a relation', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Tee']);
    $v1 = FakeVariant::query()->create(['product_id' => $p->id, 'color_uk' => 'Червоний', 'color_en' => 'Red', 'color_hex' => '#ff0000', 'size' => 'M', 'price' => 199.99, 'stock_qty' => 5]);
    FakeVariant::query()->create(['product_id' => $p->id, 'color_uk' => 'Синій', 'color_en' => 'Blue', 'color_hex' => '#0000ff', 'size' => 'L', 'price' => 199.99, 'stock_qty' => 0]);

    $payload = (new EntityMapper(EntityKind::Product, variationConfig()))->map($p)->payload;

    expect($payload['variant_axes'])->toHaveCount(2);
    expect($payload['variant_axes'][0])->toMatchArray(['name' => 'Колір', 'slug' => 'color']);
    expect($payload['variant_axes'][0]['values'])->toHaveCount(2);
    expect($payload['variant_axes'][0]['values'][0])->toBe(['label' => 'Червоний', 'external_id' => 'laravel:option:color:ff0000']);
    expect($payload['variant_axes'][1]['slug'])->toBe('size');

    expect($payload['variations'])->toHaveCount(2);
    expect($payload['variations'][0])->toMatchArray([
        'external_id' => 'laravel:variation:'.$v1->id,
        'attributes' => ['Колір' => 'Червоний', 'Розмір' => 'M'],
        'price_amount' => 19999,
        'stock_status' => 'instock',
    ]);
    expect($payload['variations'][1]['attributes'])->toBe(['Колір' => 'Синій', 'Розмір' => 'L']);
    expect($payload['variations'][1]['stock_status'])->toBe('outofstock');
});

it('localizes axis names and value labels per locale', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Tee']);
    FakeVariant::query()->create(['product_id' => $p->id, 'color_uk' => 'Червоний', 'color_en' => 'Red', 'color_hex' => '#ff0000', 'size' => 'M', 'price' => 10, 'stock_qty' => 1]);

    $config = variationConfig();
    $config['translations'] = ['base_locale' => 'uk', 'locales' => ['uk', 'en']];
    $config['map'] = ['payload.name' => fn (FakeProduct $m, ?string $l): string => (string) $m->title];

    $rows = (new EntityMapper(EntityKind::Product, $config))->mapRows($p);
    $en = collect($rows)->firstWhere('lang', 'en');

    expect($en)->not->toBeNull();
    expect($en->payload['variant_axes'][0]['name'])->toBe('Color');
    expect($en->payload['variations'][0]['attributes'])->toBe(['Color' => 'Red', 'Розмір' => 'M']);
});

it('emits no variant keys when the product has no variants', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Simple']);

    $payload = (new EntityMapper(EntityKind::Product, variationConfig()))->map($p)->payload;

    expect($payload)->not->toHaveKey('variant_axes');
    expect($payload)->not->toHaveKey('variations');
});

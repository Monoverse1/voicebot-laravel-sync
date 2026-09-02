<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Mapping\EntityMapper;
use Monoverse\VoicebotSync\Mapping\Presets;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeVariant;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('color_uk')->nullable();
        $table->string('size')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('fake_variants', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->string('color_uk');
        $table->string('size')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->integer('stock_qty')->default(0);
    });
});

it('flat-row attributes match the backend _product_facet_index shape (name/slug/values)', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Nike Air Force 1', 'price' => 100]);
    $p->forceFill(['color_uk' => 'Білий', 'size' => '38']);

    $config = [
        'external_id' => 'id',
        'map' => Presets::product(
            ['name' => 'title', 'price' => 'price'],
            attributes: ['Колір' => 'color_uk', 'Розмір' => 'size'],
        ),
    ];

    $record = (new EntityMapper(EntityKind::Product, $config))->map($p)->toNdjsonRecord();
    $attributes = $record['payload']['attributes'];

    expect($attributes)->toBeArray();
    foreach ($attributes as $axis) {
        expect($axis)->toHaveKeys(['name', 'values'])
            ->and($axis['name'])->toBeString()
            ->and($axis['values'])->toBeArray();
        foreach ($axis['values'] as $value) {
            expect($value)->toBeString();
        }
    }

    $index = [];
    foreach ($attributes as $axis) {
        $axisToken = mb_strtolower(trim((string) $axis['name']));
        foreach ($axis['values'] as $value) {
            $index[$axisToken][] = mb_strtolower(trim((string) $value));
        }
    }

    expect($index['колір'])->toContain('білий')
        ->and($index['розмір'])->toContain('38');
});

it('variations block matches the backend variant_axes + variations contract shape', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Tee', 'price' => 50]);
    FakeVariant::query()->create(['product_id' => $p->id, 'color_uk' => 'Білий', 'size' => 'M', 'price' => 50, 'stock_qty' => 3]);
    FakeVariant::query()->create(['product_id' => $p->id, 'color_uk' => 'Чорний', 'size' => 'L', 'price' => 50, 'stock_qty' => 0]);

    $config = [
        'external_id' => 'id',
        'map' => Presets::product(['name' => 'title', 'price' => 'price']),
        'variations' => Presets::variations(
            items: fn (FakeProduct $m) => FakeVariant::query()->where('product_id', $m->id)->orderBy('id')->get(),
            externalId: fn (FakeVariant $v): string => 'laravel:variation:'.$v->id,
            axes: [
                'color' => ['name' => 'Колір', 'value' => 'color_uk'],
                'size' => ['name' => 'Розмір', 'value' => 'size'],
            ],
            fields: [
                'price_amount' => fn (FakeVariant $v): int => (int) round($v->price * 100),
                'stock_status' => fn (FakeVariant $v): string => $v->stock_qty > 0 ? 'instock' : 'outofstock',
            ],
        ),
    ];

    $payload = (new EntityMapper(EntityKind::Product, $config))->map($p)->payload;

    expect($payload['variant_axes'])->toBeArray()->toHaveCount(2);
    foreach ($payload['variant_axes'] as $axis) {
        expect($axis)->toHaveKeys(['name', 'slug', 'values'])
            ->and($axis['name'])->toBeString()
            ->and($axis['slug'])->toBeString()
            ->and($axis['values'])->toBeArray();
        foreach ($axis['values'] as $value) {
            expect($value)->toHaveKey('label')
                ->and($value['label'])->toBeString();
        }
    }

    expect($payload['variations'])->toHaveCount(2);
    foreach ($payload['variations'] as $variation) {
        expect($variation)->toHaveKeys(['external_id', 'attributes'])
            ->and($variation['external_id'])->toBeString()
            ->and($variation['attributes'])->toBeArray()
            ->and($variation['stock_status'])->toBeIn(['instock', 'outofstock'])
            ->and($variation['price_amount'])->toBeInt();
        foreach ($variation['attributes'] as $axisName => $axisValue) {
            expect($axisName)->toBeString()->and($axisValue)->toBeString();
        }
    }

    expect($payload['variations'][0]['attributes'])->toBe(['Колір' => 'Білий', 'Розмір' => 'M'])
        ->and($payload['variations'][0]['stock_status'])->toBe('instock')
        ->and($payload['variations'][1]['attributes'])->toBe(['Колір' => 'Чорний', 'Розмір' => 'L'])
        ->and($payload['variations'][1]['stock_status'])->toBe('outofstock');
});

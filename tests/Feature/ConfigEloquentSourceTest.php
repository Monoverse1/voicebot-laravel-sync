<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Mapping\EntityMapper;
use Monoverse\VoicebotSync\Sources\ConfigEloquentSource;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
});

function productSource(): ConfigEloquentSource
{
    $config = [
        'model' => FakeProduct::class,
        'external_id' => 'id',
        'updated_at' => 'updated_at',
        'map' => ['payload.name' => 'title'],
    ];

    return new ConfigEloquentSource(EntityKind::Product, $config, new EntityMapper(EntityKind::Product, $config), 200);
}

it('streams all rows when since is null (full snapshot)', function (): void {
    FakeProduct::query()->create(['title' => 'A']);
    FakeProduct::query()->create(['title' => 'B']);

    $all = productSource()->upserts(null)->all();

    expect($all)->toHaveCount(2)
        ->and($all[0])->toBeInstanceOf(CanonicalEntity::class);
});

it('selects only rows changed strictly after the watermark (delta)', function (): void {
    $old = FakeProduct::query()->create(['title' => 'Old']);
    $old->forceFill(['updated_at' => CarbonImmutable::parse('2026-01-01T00:00:00Z')])->saveQuietly();

    $fresh = FakeProduct::query()->create(['title' => 'Fresh']);
    $fresh->forceFill(['updated_at' => CarbonImmutable::parse('2026-06-01T00:00:00Z')])->saveQuietly();

    $delta = productSource()->upserts(CarbonImmutable::parse('2026-03-01T00:00:00Z'))->all();

    expect($delta)->toHaveCount(1)
        ->and($delta[0]->payload['name'])->toBe('Fresh');
});

it('emits delete operations for soft-deleted rows after the watermark', function (): void {
    $product = FakeProduct::query()->create(['title' => 'Gone']);
    $product->delete();

    $deletes = productSource()->deletes(CarbonImmutable::parse('2026-01-01T00:00:00Z'))->all();

    expect($deletes)->toHaveCount(1)
        ->and($deletes[0])->toMatchArray([
            'type' => 'delete',
            'entity_kind' => 'product',
            'external_id' => 'laravel:product:'.$product->id,
        ]);
});

it('returns no deletes on a full snapshot (since is null)', function (): void {
    $product = FakeProduct::query()->create(['title' => 'Gone']);
    $product->delete();

    expect(productSource()->deletes(null)->all())->toBe([]);
});

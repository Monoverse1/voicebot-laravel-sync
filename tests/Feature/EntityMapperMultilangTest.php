<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Mapping\EntityMapper;
use Monoverse\VoicebotSync\Sources\ConfigEloquentSource;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('name_ru')->nullable();
        $table->string('name_en')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
});

/** @return array<string, mixed> */
function multilangConfig(): array
{
    return [
        'model' => FakeProduct::class,
        'external_id' => 'id',
        'translations' => [
            'base_locale' => 'uk',
            'locales' => ['uk', 'ru', 'en'],
            'present' => fn (FakeProduct $m, string $l): bool => $l === 'uk' || filled($m->{'name_'.$l}),
        ],
        'map' => [
            'payload.name' => fn (FakeProduct $m, ?string $l): string => $l === null || $l === 'uk'
                ? (string) $m->title
                : (string) $m->{'name_'.$l},
        ],
    ];
}

it('fans one row out to a base entity plus one per present locale', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Чашка', 'name_ru' => 'Кружка', 'name_en' => 'Mug']);

    $rows = (new EntityMapper(EntityKind::Product, multilangConfig()))->mapRows($p);

    expect($rows)->toHaveCount(3);

    expect($rows[0]->externalId)->toBe('laravel:product:'.$p->id)
        ->and($rows[0]->lang)->toBe('uk')
        ->and($rows[0]->translationOf)->toBeNull()
        ->and($rows[0]->payload['name'])->toBe('Чашка');

    expect($rows[1]->externalId)->toBe('laravel:product:'.$p->id.':ru')
        ->and($rows[1]->lang)->toBe('ru')
        ->and($rows[1]->translationOf)->toBe('laravel:product:'.$p->id)
        ->and($rows[1]->payload['name'])->toBe('Кружка');

    expect($rows[2]->externalId)->toBe('laravel:product:'.$p->id.':en')
        ->and($rows[2]->payload['name'])->toBe('Mug');
});

it('omits a locale whose present closure is false', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Чашка', 'name_ru' => null, 'name_en' => 'Mug']);

    $rows = (new EntityMapper(EntityKind::Product, multilangConfig()))->mapRows($p);

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('lang')->all())->toBe(['uk', 'en']);
});

it('map() returns the canonical base entity for back-compat', function (): void {
    $p = FakeProduct::query()->create(['title' => 'X', 'name_ru' => 'Y']);

    $entity = (new EntityMapper(EntityKind::Product, multilangConfig()))->map($p);

    expect($entity->externalId)->toBe('laravel:product:'.$p->id)
        ->and($entity->translationOf)->toBeNull();
});

it('falls back to a single base entity when no translations block is set', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Plain']);

    $rows = (new EntityMapper(EntityKind::Product, [
        'external_id' => 'id',
        'map' => ['payload.name' => 'title'],
    ]))->mapRows($p);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->lang)->toBeNull();
});

it('streams every locale entity through ConfigEloquentSource', function (): void {
    FakeProduct::query()->create(['title' => 'A', 'name_ru' => 'А', 'name_en' => null]);
    FakeProduct::query()->create(['title' => 'B', 'name_ru' => 'Б', 'name_en' => 'Bee']);

    $config = multilangConfig();
    $source = new ConfigEloquentSource(EntityKind::Product, $config, new EntityMapper(EntityKind::Product, $config), 200);

    expect($source->upserts(null)->all())->toHaveCount(5);
});

it('emits deletes for the base and present locales of a soft-deleted row', function (): void {
    $p = FakeProduct::query()->create(['title' => 'Gone', 'name_ru' => 'Видалено', 'name_en' => null]);
    $p->delete();

    $config = multilangConfig();
    $source = new ConfigEloquentSource(EntityKind::Product, $config, new EntityMapper(EntityKind::Product, $config), 200);

    $deletes = $source->deletes(CarbonImmutable::parse('2026-01-01T00:00:00Z'))->all();

    expect(collect($deletes)->pluck('external_id')->all())->toBe([
        'laravel:product:'.$p->id,
        'laravel:product:'.$p->id.':ru',
    ]);
});

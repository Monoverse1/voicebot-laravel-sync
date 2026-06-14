<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Models\VoicebotDeadLetter;
use Monoverse\VoicebotSync\Support\DeadLetter;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Support\Watermark;
use Monoverse\VoicebotSync\Sync\DeltaSync;
use Monoverse\VoicebotSync\Tests\Fixtures\ArraySource;

beforeEach(function (): void {
    app(SecretStore::class)->store('66666666-6666-6666-6666-666666666666', random_bytes(32), 'https://api.test.local');
});

/** @return list<CanonicalEntity> */
function makeProducts(int $count): array
{
    $out = [];
    for ($i = 1; $i <= $count; $i++) {
        $out[] = new CanonicalEntity(EntityKind::Product, "laravel:product:{$i}", ['name' => "P{$i}"]);
    }

    return $out;
}

it('splits more than 500 ops into multiple batches', function (): void {
    Http::fake([
        '*/api/v1/ingest/events' => Http::response(['processed' => 0, 'errors' => [], 'idempotent_replay' => false], 200),
    ]);

    $sources = [new ArraySource(EntityKind::Product, makeProducts(1201))];
    $result = app(DeltaSync::class)->run($sources, false);

    // 1201 ops at a 500 cap -> 500 + 500 + 201 = 3 batches.
    expect($result->batches)->toBe(3)
        ->and($result->deadLettered)->toBe(0);

    Http::assertSentCount(3);
});

it('dead-letters a batch on a permanent 500 and does NOT advance the watermark', function (): void {
    Http::fake([
        '*/api/v1/ingest/events' => Http::response(['error' => ['code' => 'boom']], 500),
    ]);

    $wm = app(Watermark::class);
    expect($wm->get('product'))->toBeNull();

    $sources = [new ArraySource(EntityKind::Product, makeProducts(3))];
    $result = app(DeltaSync::class)->run($sources, false);

    expect($result->deadLettered)->toBe(3)
        ->and(VoicebotDeadLetter::query()->count())->toBe(3)
        ->and($wm->get('product'))->toBeNull(); // failure must not advance
});

it('advances the watermark only on full success', function (): void {
    Http::fake([
        '*/api/v1/ingest/events' => Http::response(['processed' => 2, 'errors' => [], 'idempotent_replay' => false], 200),
    ]);

    $wm = app(Watermark::class);
    $sources = [new ArraySource(EntityKind::Product, makeProducts(2))];

    app(DeltaSync::class)->run($sources, false);

    expect($wm->get('product'))->not->toBeNull();
});

it('honours a --since override instead of the stored watermark', function (): void {
    Http::fake([
        '*/api/v1/ingest/events' => Http::response(['processed' => 1, 'errors' => [], 'idempotent_replay' => false], 200),
    ]);

    $since = CarbonImmutable::parse('2026-01-01T00:00:00Z');
    $sources = [new ArraySource(EntityKind::Product, makeProducts(1))];

    $result = app(DeltaSync::class)->run($sources, false, $since);

    expect($result->processed)->toBe(1);
});

it('dry-run counts ops and pushes nothing', function (): void {
    Http::fake();

    $sources = [new ArraySource(EntityKind::Product, makeProducts(4))];
    $result = app(DeltaSync::class)->run($sources, true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->processed)->toBe(4);

    Http::assertNothingSent();
});

it('accumulates attempts on the same op and flags exhausted at the configured cap', function (): void {
    config()->set('voicebot.sync.dead_letter_max_attempts', 2);
    app()->forgetInstance(DeadLetter::class);
    app()->forgetInstance(DeltaSync::class);

    Http::fake(['*/api/v1/ingest/events' => Http::response(['error' => ['code' => 'boom']], 500)]);
    $sources = [new ArraySource(EntityKind::Product, makeProducts(1))];

    // Run 1: the op fails once and is parked with attempts=1, not yet exhausted.
    app(DeltaSync::class)->run($sources, false);
    $row = VoicebotDeadLetter::query()->firstOrFail();
    expect(VoicebotDeadLetter::query()->count())->toBe(1)
        ->and($row->attempts)->toBe(1)
        ->and($row->exhausted)->toBeFalse();

    // Run 2: same op identity → attempts bumps to 2 (the cap), no duplicate row, exhausted.
    app(DeltaSync::class)->run($sources, false);
    $row->refresh();
    expect(VoicebotDeadLetter::query()->count())->toBe(1)
        ->and($row->attempts)->toBe(2)
        ->and($row->exhausted)->toBeTrue();
});

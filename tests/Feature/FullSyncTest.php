<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Dto\CanonicalEntity;
use Monoverse\VoicebotSync\Dto\EntityKind;
use Monoverse\VoicebotSync\Exceptions\VoicebotSyncException;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Support\Watermark;
use Monoverse\VoicebotSync\Sync\FullSync;
use Monoverse\VoicebotSync\Tests\Fixtures\ArraySource;

beforeEach(function (): void {
    app(SecretStore::class)->store('55555555-5555-5555-5555-555555555555', random_bytes(32), 'https://api.test.local');
});

it('runs init -> upload -> finalize with expected_counts equal to streamed counts', function (): void {
    Http::fake([
        '*/api/v1/ingest/init' => Http::response(['sync_id' => 'sync-1', 'upload_url' => 'https://up.test/put'], 200),
        'https://up.test/*' => Http::response('', 200),
        '*/api/v1/ingest/finalize' => Http::response(['sync_id' => 'sync-1', 'status' => 'dispatching'], 200),
    ]);

    $sources = [
        new ArraySource(EntityKind::Product, [
            new CanonicalEntity(EntityKind::Product, 'laravel:product:1', ['name' => 'A']),
            new CanonicalEntity(EntityKind::Product, 'laravel:product:2', ['name' => 'B']),
        ]),
        new ArraySource(EntityKind::Page, [
            new CanonicalEntity(EntityKind::Page, 'laravel:page:1', ['title' => 'Home']),
        ]),
    ];

    $result = app(FullSync::class)->run($sources, false);

    expect($result->total)->toBe(3)
        ->and($result->counts)->toBe(['product' => 2, 'page' => 1])
        ->and($result->syncId)->toBe('sync-1');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/api/v1/ingest/init')) {
            return false;
        }
        $body = json_decode((string) $request->body(), true);

        return $body['expected_counts'] === ['product' => 2, 'page' => 1];
    });

    // Watermark advanced for every synced kind.
    $wm = app(Watermark::class);
    expect($wm->get('product'))->not->toBeNull()
        ->and($wm->get('page'))->not->toBeNull();
});

it('refuses to push an empty snapshot (tombstone guard)', function (): void {
    Http::fake();

    $sources = [new ArraySource(EntityKind::Product, [])];

    expect(fn () => app(FullSync::class)->run($sources, false))
        ->toThrow(VoicebotSyncException::class, 'empty full snapshot');

    Http::assertNothingSent();
});

it('dry-run computes counts and pushes nothing', function (): void {
    Http::fake();

    $sources = [new ArraySource(EntityKind::Product, [
        new CanonicalEntity(EntityKind::Product, 'laravel:product:1', ['name' => 'A']),
    ])];

    $result = app(FullSync::class)->run($sources, true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->total)->toBe(1)
        ->and($result->syncId)->toBeNull();

    Http::assertNothingSent();
});

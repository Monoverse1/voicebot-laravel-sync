<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Jobs\SyncCatalogJob;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

function seedProductEntity(): void
{
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    config()->set('voicebot.entities', [
        'product' => [
            'enabled' => true,
            'model' => FakeProduct::class,
            'external_id' => 'id',
            'map' => ['payload.name' => 'title'],
        ],
    ]);
}

it('exits INVALID(2) when not paired', function (): void {
    config()->set('voicebot.entities', []);

    $this->artisan('voicebot:sync')->assertExitCode(2);
});

it('dispatches a queue job with --queue and pushes nothing inline', function (): void {
    app(SecretStore::class)->store('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', random_bytes(32), 'https://api.test.local');
    Bus::fake();
    Http::fake();

    $this->artisan('voicebot:sync', ['--queue' => true, '--full' => true])->assertExitCode(0);

    Bus::assertDispatched(SyncCatalogJob::class, fn (SyncCatalogJob $job): bool => $job->full === true);
    Http::assertNothingSent();
});

it('runs a full snapshot inline and reports counts', function (): void {
    app(SecretStore::class)->store('cccccccc-cccc-cccc-cccc-cccccccccccc', random_bytes(32), 'https://api.test.local');
    seedProductEntity();
    FakeProduct::query()->create(['title' => 'Widget']);

    Http::fake([
        '*/api/v1/ingest/init' => Http::response(['sync_id' => 'sync-x', 'upload_url' => 'https://up.test/put'], 200),
        'https://up.test/*' => Http::response('', 200),
        '*/api/v1/ingest/finalize' => Http::response(['sync_id' => 'sync-x', 'status' => 'dispatching'], 200),
    ]);

    $this->artisan('voicebot:sync', ['--full' => true])
        ->expectsOutputToContain('Full snapshot: 1 records.')
        ->assertExitCode(0);
});

it('dry-run full pushes nothing', function (): void {
    app(SecretStore::class)->store('dddddddd-dddd-dddd-dddd-dddddddddddd', random_bytes(32), 'https://api.test.local');
    seedProductEntity();
    FakeProduct::query()->create(['title' => 'Widget']);
    Http::fake();

    $this->artisan('voicebot:sync', ['--full' => true, '--dry-run' => true])->assertExitCode(0);

    Http::assertNothingSent();
});

it('exits INVALID(2) on an empty full snapshot (config error)', function (): void {
    app(SecretStore::class)->store('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee', random_bytes(32), 'https://api.test.local');
    seedProductEntity(); // table exists but no rows
    Http::fake();

    $this->artisan('voicebot:sync', ['--full' => true])->assertExitCode(2);
});

it('rejects --since with --full', function (): void {
    app(SecretStore::class)->store('ffffffff-ffff-ffff-ffff-ffffffffffff', random_bytes(32), 'https://api.test.local');
    config()->set('voicebot.entities', []);

    $this->artisan('voicebot:sync', ['--full' => true, '--since' => '2026-01-01'])->assertExitCode(2);
});

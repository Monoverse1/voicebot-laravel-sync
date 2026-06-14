<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Tests\Fixtures\FakeProduct;

beforeEach(function (): void {
    Schema::create('fake_products', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    app(SecretStore::class)->store('77777777-7777-7777-7777-777777777777', random_bytes(32), 'https://api.test.local');
    Http::fake([
        '*/api/v1/ingest/status' => Http::response(['tenant_id' => '77777777-7777-7777-7777-777777777777', 'provider_status' => 'connected'], 200),
    ]);
    config()->set('voicebot.entities', []);
});

it('passes when an enabled kind maps cleanly with required fields', function (): void {
    FakeProduct::query()->create(['title' => 'Mug', 'price' => 10]);
    config()->set('voicebot.entities.product', [
        'enabled' => true,
        'model' => FakeProduct::class,
        'external_id' => 'id',
        'map' => ['payload.name' => 'title'],
    ]);

    $this->artisan('voicebot:doctor')
        ->expectsOutputToContain('PASS')
        ->assertExitCode(0);
});

it('fails loudly when a mapped kind is missing its required payload field', function (): void {
    FakeProduct::query()->create(['title' => 'Mug', 'price' => 10]);
    // product requires `name`, but the map only sets `sku` -> missing required key.
    config()->set('voicebot.entities.product', [
        'enabled' => true,
        'model' => FakeProduct::class,
        'external_id' => 'id',
        'map' => ['payload.sku' => 'title'],
    ]);

    $this->artisan('voicebot:doctor')
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);
});

it('fails loudly when an enabled kind has no resolvable model', function (): void {
    config()->set('voicebot.entities.product', [
        'enabled' => true,
        'model' => null,
        'external_id' => 'id',
        'map' => ['payload.name' => 'title'],
    ]);

    $this->artisan('voicebot:doctor')->assertExitCode(1);
});

it('fails when not paired', function (): void {
    app(SecretStore::class)->clear();

    $this->artisan('voicebot:doctor')
        ->expectsOutputToContain('not paired')
        ->assertExitCode(1);
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Protocol\Protocol;
use Monoverse\VoicebotSync\Support\SecretStore;

it('signs the unpair request and clears local credentials', function (): void {
    app(SecretStore::class)->store('22222222-2222-2222-2222-222222222222', random_bytes(32), 'https://api.test.local');
    Http::fake(['*/api/v1/ingest/unpair' => Http::response(['ok' => true, 'affected' => 1], 200)]);

    $this->artisan('voicebot:unpair')->assertExitCode(0);

    expect(app(SecretStore::class)->isPaired())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/ingest/unpair')
        && $request->hasHeader(Protocol::HEADER_SIGNATURE)
        && $request->header(Protocol::HEADER_PROTOCOL)[0] === '2');
});

it('clears local credentials even when the remote unpair fails', function (): void {
    app(SecretStore::class)->store('33333333-3333-3333-3333-333333333333', random_bytes(32), 'https://api.test.local');
    Http::fake(['*/api/v1/ingest/unpair' => Http::response(['error' => ['code' => 'boom']], 500)]);

    // Non-zero (remote failed) but the operator is no longer stuck paired.
    $this->artisan('voicebot:unpair')->assertExitCode(1);

    expect(app(SecretStore::class)->isPaired())->toBeFalse();
});

it('exits INVALID(2) when not paired', function (): void {
    $this->artisan('voicebot:unpair')->assertExitCode(2);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Support\SecretStore;

it('pairs and stores the secret encrypted at rest', function (): void {
    $secretRaw = random_bytes(32);
    $secretB64 = base64_encode($secretRaw);

    Http::fake([
        '*/api/v1/ingest/pair' => Http::response([
            'tenant_id' => '22222222-2222-2222-2222-222222222222',
            'shared_secret_b64' => $secretB64,
            'ingest_url' => 'https://api.test.local',
            'protocol_version' => 1,
        ], 200),
    ]);

    $this->artisan('voicebot:pair', ['code' => 'VB-TEST-CODE'])
        ->expectsOutputToContain('22222222-2222-2222-2222-222222222222')
        ->assertExitCode(0);

    // The shared secret must NEVER hit the console.
    $secrets = app(SecretStore::class);
    expect($secrets->isPaired())->toBeTrue()
        ->and($secrets->tenantId())->toBe('22222222-2222-2222-2222-222222222222')
        ->and($secrets->secretRaw())->toBe($secretRaw);

    // The raw DB column holds ciphertext, not the plaintext base64.
    $stored = DB::table('voicebot_connections')->value('secret_b64');
    expect($stored)->not->toBe($secretB64)
        ->and($stored)->not->toContain($secretB64);
});

it('exits INVALID(2) when no pair code is available', function (): void {
    config()->set('voicebot.pair_code', null);

    $this->artisan('voicebot:pair')->assertExitCode(2);
});

it('exits INVALID(2) when already paired', function (): void {
    app(SecretStore::class)->store('33333333-3333-3333-3333-333333333333', random_bytes(32), 'https://api.test.local');

    $this->artisan('voicebot:pair', ['code' => 'VB-AGAIN'])->assertExitCode(2);
});

it('sends locale and timezone metadata on pair', function (): void {
    config()->set('app.locale', 'uk');
    config()->set('app.timezone', 'Europe/Kyiv');

    Http::fake([
        '*/api/v1/ingest/pair' => Http::response([
            'tenant_id' => '44444444-4444-4444-4444-444444444444',
            'shared_secret_b64' => base64_encode(random_bytes(32)),
            'ingest_url' => 'https://api.test.local',
        ], 200),
    ]);

    $this->artisan('voicebot:pair', ['code' => 'VB-META'])->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        $body = json_decode((string) $request->body(), true);

        return ($body['locale'] ?? null) === 'uk'
            && ($body['timezone'] ?? null) === 'Europe/Kyiv';
    });
});

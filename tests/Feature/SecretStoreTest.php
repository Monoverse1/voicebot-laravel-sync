<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Monoverse\VoicebotSync\Support\SecretStore;

it('round-trips raw secret bytes through base64 and the encrypted column', function (): void {
    $store = app(SecretStore::class);
    // Include a NUL byte — guards against any string-truncation in the round-trip.
    $secretRaw = "\x00\x01\xfe\xffraw-secret-bytes-\x10\x20";

    $store->store('88888888-8888-8888-8888-888888888888', $secretRaw, 'https://api.test.local/');

    expect($store->secretRaw())->toBe($secretRaw)
        ->and($store->tenantId())->toBe('88888888-8888-8888-8888-888888888888')
        ->and($store->ingestUrl())->toBe('https://api.test.local'); // trailing slash trimmed
});

it('never stores the secret in plaintext', function (): void {
    $store = app(SecretStore::class);
    $secretRaw = random_bytes(32);
    $store->store('99999999-9999-9999-9999-999999999999', $secretRaw, 'https://api.test.local');

    $ciphertext = (string) DB::table('voicebot_connections')->value('secret_b64');

    expect($ciphertext)->not->toContain(base64_encode($secretRaw))
        ->and(base64_decode($ciphertext, true))->not->toBe($secretRaw);
});

it('reports unpaired after clear', function (): void {
    $store = app(SecretStore::class);
    $store->store('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', random_bytes(32), 'https://api.test.local');
    expect($store->isPaired())->toBeTrue();

    $store->clear();

    expect($store->isPaired())->toBeFalse()
        ->and($store->secretRaw())->toBeNull();
});

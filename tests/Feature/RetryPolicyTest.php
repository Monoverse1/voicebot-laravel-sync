<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Exceptions\VoicebotSyncException;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Support\SecretStore;

// The default TestCase sets retry.times=1, which would mask a retry-policy bug.
// Raise it here and re-resolve the singleton so the new config takes effect.
beforeEach(function (): void {
    config()->set('voicebot.http.retry.times', 3);
    config()->set('voicebot.http.retry.base_ms', 1);
    config()->set('voicebot.http.retry.max_ms', 2);
    app()->forgetInstance(IngestClient::class);
});

it('does NOT auto-retry a SIGNED call on 5xx (the nonce is already spent)', function (): void {
    app(SecretStore::class)->store('11111111-1111-1111-1111-111111111111', random_bytes(32), 'https://api.test.local');
    Http::fake(['*/api/v1/ingest/init' => Http::response(['error' => ['code' => 'boom']], 500)]);

    expect(fn () => app(IngestClient::class)->init('full', ['product' => 1]))
        ->toThrow(VoicebotSyncException::class);

    // Exactly one attempt — retrying would replay the spent nonce → 401.
    Http::assertSentCount(1);
});

it('does NOT auto-retry a SIGNED call on 429', function (): void {
    app(SecretStore::class)->store('11111111-1111-1111-1111-111111111111', random_bytes(32), 'https://api.test.local');
    Http::fake(['*/api/v1/ingest/status' => Http::response(['error' => ['code' => 'rate']], 429)]);

    expect(fn () => app(IngestClient::class)->status())->toThrow(VoicebotSyncException::class);

    Http::assertSentCount(1);
});

it('DOES retry the UNSIGNED pair() on 5xx (no nonce consumed)', function (): void {
    Http::fake(['*/api/v1/ingest/pair' => Http::response(['error' => ['code' => 'boom']], 500)]);

    expect(fn () => app(IngestClient::class)->pair('VB-CODE', 'https://shop.test.local'))
        ->toThrow(VoicebotSyncException::class);

    // times=3 → 3 attempts total.
    Http::assertSentCount(3);
});

it('DOES retry the no-HMAC uploadFile() PUT on 5xx', function (): void {
    app(SecretStore::class)->store('11111111-1111-1111-1111-111111111111', random_bytes(32), 'https://api.test.local');
    $path = tempnam(sys_get_temp_dir(), 'vbretry_');
    file_put_contents($path, gzencode("{\"kind\":\"product\"}\n"));

    Http::fake(['https://up.test/*' => Http::response('', 503)]);

    expect(fn () => app(IngestClient::class)->uploadFile('https://up.test/abc', $path))
        ->toThrow(VoicebotSyncException::class);
    @unlink($path);

    Http::assertSentCount(3);
});

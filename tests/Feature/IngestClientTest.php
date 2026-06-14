<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Protocol\HmacSigner;
use Monoverse\VoicebotSync\Protocol\Protocol;
use Monoverse\VoicebotSync\Support\SecretStore;

function seedPairing(SecretStore $secrets): string
{
    $secretRaw = random_bytes(32);
    $secrets->store('11111111-1111-1111-1111-111111111111', $secretRaw, 'https://api.test.local');

    return $secretRaw;
}

it('sends all 7 signed headers with protocol version 2', function (): void {
    $secrets = app(SecretStore::class);
    seedPairing($secrets);

    Http::fake([
        '*/api/v1/ingest/init' => Http::response(['sync_id' => 's1', 'upload_url' => 'https://up.test/abc'], 200),
    ]);

    app(IngestClient::class)->init('full', ['product' => 2]);

    Http::assertSent(function (Request $request): bool {
        foreach ([
            Protocol::HEADER_TENANT,
            Protocol::HEADER_TIMESTAMP,
            Protocol::HEADER_NONCE,
            Protocol::HEADER_SIGNATURE,
            Protocol::HEADER_PROTOCOL,
            Protocol::HEADER_PLUGIN_VER,
            Protocol::HEADER_SITE_URL,
        ] as $header) {
            expect($request->hasHeader($header))->toBeTrue("missing {$header}");
        }
        expect($request->header(Protocol::HEADER_PROTOCOL)[0])->toBe('2');
        expect($request->header(Protocol::HEADER_NONCE)[0])->toMatch('/^[0-9a-f]{32}$/');

        return true;
    });
});

it('signs the EXACT body that is sent (hashed == sent invariant)', function (): void {
    $secrets = app(SecretStore::class);
    $secretRaw = seedPairing($secrets);

    Http::fake([
        '*/api/v1/ingest/events' => Http::response(['processed' => 1, 'errors' => [], 'idempotent_replay' => false], 200),
    ]);

    // UTF-8 payload — guards JSON flag consistency (unescaped unicode) end to end.
    $ops = [['type' => 'upsert', 'entity_kind' => 'product', 'external_id' => 'laravel:product:1', 'payload' => ['name' => 'Кавоварка']]];
    app(IngestClient::class)->events('batch-1', $ops, 'idem-1');

    Http::assertSent(function (Request $request) use ($secretRaw): bool {
        $sentBody = (string) $request->body();
        $recomputed = (new HmacSigner($secretRaw))->sign(
            'POST',
            '/api/v1/ingest/events',
            $request->header(Protocol::HEADER_TIMESTAMP)[0],
            $request->header(Protocol::HEADER_NONCE)[0],
            HmacSigner::bodyHash($sentBody),
        );

        expect($request->header(Protocol::HEADER_SIGNATURE)[0])->toBe($recomputed)
            ->and($sentBody)->toContain('Кавоварка');

        return true;
    });
});

it('attaches the idempotency key on events and finalize', function (): void {
    $secrets = app(SecretStore::class);
    seedPairing($secrets);

    Http::fake([
        '*/api/v1/ingest/finalize' => Http::response(['sync_id' => 's1', 'status' => 'dispatching'], 200),
    ]);

    app(IngestClient::class)->finalize('s1', 'idem-final');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(Protocol::HEADER_IDEMPOTENCY)
        && $request->header(Protocol::HEADER_IDEMPOTENCY)[0] === 'idem-final');
});

it('streams the snapshot file on upload without re-signing', function (): void {
    $secrets = app(SecretStore::class);
    seedPairing($secrets);

    $path = tempnam(sys_get_temp_dir(), 'vbtest_');
    file_put_contents($path, gzencode('{"kind":"product"}'."\n"));

    Http::fake(['https://up.test/*' => Http::response('', 200)]);

    app(IngestClient::class)->uploadFile('https://up.test/abc', $path);
    @unlink($path);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader(Protocol::HEADER_SIGNATURE));
});

<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Monoverse\VoicebotSync\Http\SyncTriggerController;
use Monoverse\VoicebotSync\Jobs\SyncCatalogJob;
use Monoverse\VoicebotSync\Protocol\Protocol;
use Monoverse\VoicebotSync\Support\SecretStore;

const TRIGGER_TENANT = '99999999-9999-9999-9999-999999999999';
const TRIGGER_PATH = '/voicebot/sync';

function seedTriggerPairing(): string
{
    $secretRaw = random_bytes(32);
    app(SecretStore::class)->store(TRIGGER_TENANT, $secretRaw, 'https://api.test.local');

    return $secretRaw;
}

/** @param array<string, string> $overrides */
function triggerRequest(string $secretRaw, array $overrides = [], string $body = '{"mode":"full"}'): Request
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $inboundPath = TRIGGER_PATH;
    $bodyHash = hash('sha256', $body);
    $signature = hash_hmac(
        'sha256',
        "POST\n{$inboundPath}\n{$timestamp}\n{$nonce}\n{$bodyHash}",
        $secretRaw,
    );

    $headers = array_merge([
        Protocol::HEADER_TENANT => TRIGGER_TENANT,
        Protocol::HEADER_TIMESTAMP => $timestamp,
        Protocol::HEADER_NONCE => $nonce,
        Protocol::HEADER_SIGNATURE => $signature,
        Protocol::HEADER_PROTOCOL => Protocol::VERSION,
        Protocol::HEADER_PLUGIN_VER => Protocol::pluginVersionHeader(),
        Protocol::HEADER_INBOUND_PATH => $inboundPath,
    ], $overrides);

    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create(TRIGGER_PATH, 'POST', [], [], [], $server, $body);
}

function invokeTrigger(Request $request): array
{
    $response = app(SyncTriggerController::class)($request);

    return [$response->getStatusCode(), $response->getData(true)];
}

it('accepts a valid signed trigger and dispatches a full SyncCatalogJob', function (): void {
    $secret = seedTriggerPairing();
    Bus::fake();

    [$status, $payload] = invokeTrigger(triggerRequest($secret));

    expect($status)->toBe(202)
        ->and($payload)->toMatchArray(['accepted' => true, 'mode' => 'full']);
    Bus::assertDispatched(SyncCatalogJob::class, fn (SyncCatalogJob $job): bool => $job->full === true);
});

it('rejects a tampered signature and dispatches nothing', function (): void {
    $secret = seedTriggerPairing();
    Bus::fake();

    [$status, $payload] = invokeTrigger(triggerRequest($secret, [
        Protocol::HEADER_SIGNATURE => str_repeat('0', 64),
    ]));

    expect($status)->toBe(401)
        ->and($payload['error']['code'])->toBe('bad_signature');
    Bus::assertNotDispatched(SyncCatalogJob::class);
});

it('rejects a tenant mismatch', function (): void {
    $secret = seedTriggerPairing();
    Bus::fake();

    [$status, $payload] = invokeTrigger(triggerRequest($secret, [
        Protocol::HEADER_TENANT => '00000000-0000-0000-0000-000000000000',
    ]));

    expect($status)->toBe(403)
        ->and($payload['error']['code'])->toBe('tenant_mismatch');
    Bus::assertNotDispatched(SyncCatalogJob::class);
});

it('rejects a stale timestamp outside the replay window', function (): void {
    $secret = seedTriggerPairing();
    Bus::fake();
    $staleTs = (string) (time() - Protocol::REPLAY_WINDOW_SECONDS - 60);
    $nonce = bin2hex(random_bytes(16));
    $body = '{"mode":"full"}';
    $bodyHash = hash('sha256', $body);
    $signature = hash_hmac('sha256', "POST\n".TRIGGER_PATH."\n{$staleTs}\n{$nonce}\n{$bodyHash}", $secret);

    [$status, $payload] = invokeTrigger(triggerRequest($secret, [
        Protocol::HEADER_TIMESTAMP => $staleTs,
        Protocol::HEADER_NONCE => $nonce,
        Protocol::HEADER_SIGNATURE => $signature,
    ]));

    expect($status)->toBe(401)
        ->and($payload['error']['code'])->toBe('stale_timestamp');
    Bus::assertNotDispatched(SyncCatalogJob::class);
});

it('rejects an inbound-path that does not equal the canonical route path', function (): void {
    $secret = seedTriggerPairing();
    Bus::fake();
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $forgedPath = '/voicebot/other';
    $body = '{"mode":"full"}';
    $bodyHash = hash('sha256', $body);
    $signature = hash_hmac('sha256', "POST\n{$forgedPath}\n{$timestamp}\n{$nonce}\n{$bodyHash}", $secret);

    [$status, $payload] = invokeTrigger(triggerRequest($secret, [
        Protocol::HEADER_TIMESTAMP => $timestamp,
        Protocol::HEADER_NONCE => $nonce,
        Protocol::HEADER_SIGNATURE => $signature,
        Protocol::HEADER_INBOUND_PATH => $forgedPath,
    ]));

    expect($status)->toBe(401)
        ->and($payload['error']['code'])->toBe('inbound_path_mismatch');
    Bus::assertNotDispatched(SyncCatalogJob::class);
});

it('rejects a replayed nonce on the second identical request', function (): void {
    $secret = seedTriggerPairing();
    Bus::fake();
    $request = triggerRequest($secret);

    [$firstStatus] = invokeTrigger($request);
    [$secondStatus, $payload] = invokeTrigger($request);

    expect($firstStatus)->toBe(202)
        ->and($secondStatus)->toBe(401)
        ->and($payload['error']['code'])->toBe('nonce_replay');
    Bus::assertDispatchedTimes(SyncCatalogJob::class, 1);
});

it('rejects when the plugin is not paired', function (): void {
    Bus::fake();

    [$status, $payload] = invokeTrigger(triggerRequest(random_bytes(32)));

    expect($status)->toBe(401)
        ->and($payload['error']['code'])->toBe('not_paired');
    Bus::assertNotDispatched(SyncCatalogJob::class);
});

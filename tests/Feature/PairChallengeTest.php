<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monoverse\VoicebotSync\Http\PairChallengeController;

it('answers the pair challenge with an hmac under the cached nonce', function (): void {
    Cache::put(PairChallengeController::NONCE_CACHE_KEY, str_repeat('0123456789abcdef', 4), 600);

    $this->get('/voicebot/pair-challenge?challenge=challenge-1837')
        ->assertOk()
        ->assertExactJson(['proof' => 'f82e51eebd3afda27c1e8f304bd4e265f3dbe4e8d1501b09b9d11f779e1771a8']);
});

it('answers 404 while no pairing is in progress', function (): void {
    $this->get('/voicebot/pair-challenge?challenge=challenge-1837')->assertNotFound();
});

it('sends the cached nonce with a pk_ pairing and forgets it after', function (): void {
    $sent = null;
    $cached = null;
    Http::fake(function ($request) use (&$sent, &$cached) {
        $sent = json_decode((string) $request->body(), true)['pair_nonce'] ?? null;
        $cached = Cache::get(PairChallengeController::NONCE_CACHE_KEY);

        return Http::response([
            'tenant_id' => '66666666-6666-6666-6666-666666666666',
            'shared_secret_b64' => base64_encode(random_bytes(32)),
            'ingest_url' => 'https://api.test.local',
            'protocol_version' => 1,
        ], 200);
    });

    $this->artisan('voicebot:pair', ['credential' => 'pk_live_storefront'])->assertExitCode(0);

    expect($sent)->toBeString()->toHaveLength(64)
        ->and($cached)->toBe($sent)
        ->and(Cache::get(PairChallengeController::NONCE_CACHE_KEY))->toBeNull();
});

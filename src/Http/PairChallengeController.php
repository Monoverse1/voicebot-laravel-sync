<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PairChallengeController
{
    public const CANONICAL_PATH = '/voicebot/pair-challenge';

    public const NONCE_CACHE_KEY = 'voicebot:pair-nonce';

    public function __invoke(Request $request): JsonResponse
    {
        $nonce = Cache::get(self::NONCE_CACHE_KEY);
        $challenge = $request->query('challenge');

        if (! is_string($nonce) || $nonce === '' || ! is_string($challenge) || $challenge === '') {
            return new JsonResponse(['error' => ['code' => 'no_pairing_in_progress']], 404, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['proof' => hash_hmac('sha256', $challenge, $nonce)], 200, ['Cache-Control' => 'no-store']);
    }
}

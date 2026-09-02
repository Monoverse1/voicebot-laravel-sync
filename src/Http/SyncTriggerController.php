<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Monoverse\VoicebotSync\Jobs\SyncCatalogJob;
use Monoverse\VoicebotSync\Protocol\HmacSigner;
use Monoverse\VoicebotSync\Protocol\InboundVerifier;
use Monoverse\VoicebotSync\Protocol\Protocol;

final class SyncTriggerController
{
    public const CANONICAL_PATH = '/voicebot/sync';

    public function __construct(private readonly InboundVerifier $verifier) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        $result = $this->verifier->verify(
            $request->getMethod(),
            (string) $request->header(Protocol::HEADER_INBOUND_PATH, ''),
            self::CANONICAL_PATH,
            (string) $request->header(Protocol::HEADER_TENANT, ''),
            (string) $request->header(Protocol::HEADER_TIMESTAMP, ''),
            (string) $request->header(Protocol::HEADER_NONCE, ''),
            HmacSigner::bodyHash($rawBody),
            (string) $request->header(Protocol::HEADER_SIGNATURE, ''),
        );

        if (! $result->ok) {
            return new JsonResponse(
                ['error' => ['code' => $result->errorCode]],
                $result->statusCode,
            );
        }

        SyncCatalogJob::dispatch(true);

        return new JsonResponse(['accepted' => true, 'mode' => 'full'], 202);
    }
}

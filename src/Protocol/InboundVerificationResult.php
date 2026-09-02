<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Protocol;

final class InboundVerificationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $errorCode,
        public readonly int $statusCode,
    ) {}

    public static function accept(): self
    {
        return new self(true, null, 202);
    }

    public static function reject(string $errorCode, int $statusCode): self
    {
        return new self(false, $errorCode, $statusCode);
    }
}

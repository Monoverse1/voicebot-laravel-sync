<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Protocol;

/**
 * HMAC-SHA256 request signer. The canonical string and digest match the backend
 * verifier byte-for-byte (apps/api/.../ingest/auth.py compute_signature):
 *   METHOD\npath\nts\nnonce\nbody_sha256   ->   hash_hmac('sha256', ., secretRaw)
 */
final class HmacSigner
{
    /** @param string $secretRaw the raw (base64-decoded) shared-secret bytes */
    public function __construct(private readonly string $secretRaw) {}

    public function sign(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $bodySha256,
    ): string {
        $payload = strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodySha256;

        return hash_hmac('sha256', $payload, $this->secretRaw);
    }

    public static function bodyHash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(Protocol::NONCE_LENGTH_BYTES));
    }
}

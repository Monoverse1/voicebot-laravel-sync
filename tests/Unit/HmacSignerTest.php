<?php

declare(strict_types=1);

use Monoverse\VoicebotSync\Protocol\HmacSigner;
use Monoverse\VoicebotSync\Protocol\Protocol;

/**
 * Conformance vector. The expected digests were produced by the SAME canonical
 * string the backend uses (apps/api/.../ingest/auth.py compute_signature):
 *   f"{method}\n{path}\n{ts}\n{nonce}\n{body_sha256}" hashed with the raw secret.
 * If this fails, the producer and verifier have diverged — every signed request breaks.
 */
// 32-byte ASCII secret "voicebot-test-shared-secret-0123"; digests recomputed in Python.
const SIGNER_SECRET_B64 = 'dm9pY2Vib3QtdGVzdC1zaGFyZWQtc2VjcmV0LTAxMjM=';

it('matches the backend HMAC vector for a signed POST body', function (): void {
    $secretRaw = base64_decode(SIGNER_SECRET_B64, true);
    expect($secretRaw)->not->toBeFalse()
        ->and(strlen((string) $secretRaw))->toBe(32);

    $body = '{"sync_type":"full","expected_counts":{"product":2}}';
    $bodyHash = HmacSigner::bodyHash($body);
    expect($bodyHash)->toBe('979a817440eab3a9845c63a94ee447301e9c5baf677487603b2fd8ca146c61de');

    $signature = (new HmacSigner((string) $secretRaw))->sign(
        'POST',
        '/api/v1/ingest/init',
        '1700000000',
        '0123456789abcdef0123456789abcdef',
        $bodyHash,
    );

    expect($signature)->toBe('e9c2f7b8d57318d4e6ad64d44aba6838d29d7bacb04cf28501e98b4ea551be17');
});

it('matches the backend HMAC vector for an empty-body GET', function (): void {
    $secretRaw = (string) base64_decode(SIGNER_SECRET_B64, true);

    $emptyHash = HmacSigner::bodyHash('');
    expect($emptyHash)->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');

    $signature = (new HmacSigner($secretRaw))->sign(
        'GET',
        '/api/v1/ingest/status',
        '1700000000',
        '0123456789abcdef0123456789abcdef',
        $emptyHash,
    );

    expect($signature)->toBe('0781df980e083c3b88b4ca9e8694bd8125a789af3378bebe4b18fdb8a7be455e');
});

it('uppercases the method exactly as the verifier does', function (): void {
    $signer = new HmacSigner('secret');
    $lower = $signer->sign('post', '/p', '1', 'n', 'h');
    $upper = $signer->sign('POST', '/p', '1', 'n', 'h');

    expect($lower)->toBe($upper);
});

it('emits a 32-hex-char nonce, matching the backend nonce width', function (): void {
    $nonce = HmacSigner::nonce();

    expect($nonce)->toMatch('/^[0-9a-f]{32}$/')
        ->and(strlen($nonce))->toBe(Protocol::NONCE_LENGTH_BYTES * 2);
});

<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Support;

use Monoverse\VoicebotSync\Models\VoicebotConnection;

/**
 * Persists and reads the paired tenant_id + shared secret. The secret is stored
 * base64-encoded inside an encrypted column; only the raw bytes ever leave this
 * class, and only to the signer. The secret is never logged or surfaced.
 */
final class SecretStore
{
    public function store(string $tenantId, string $secretRaw, string $ingestUrl): void
    {
        VoicebotConnection::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'secret_b64' => base64_encode($secretRaw),
                'ingest_url' => rtrim($ingestUrl, '/'),
                'paired_at' => now(),
            ],
        );
    }

    public function isPaired(): bool
    {
        return $this->connection() !== null;
    }

    public function tenantId(): ?string
    {
        return $this->connection()?->tenant_id;
    }

    public function ingestUrl(): ?string
    {
        return $this->connection()?->ingest_url;
    }

    public function secretRaw(): ?string
    {
        $b64 = $this->connection()?->secret_b64;
        if ($b64 === null) {
            return null;
        }
        $raw = base64_decode($b64, true);

        return $raw === false ? null : $raw;
    }

    public function clear(): void
    {
        VoicebotConnection::query()->delete();
    }

    private function connection(): ?VoicebotConnection
    {
        /** @var VoicebotConnection|null $row */
        $row = VoicebotConnection::query()->latest('id')->first();

        return $row;
    }
}

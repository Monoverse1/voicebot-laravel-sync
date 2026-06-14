<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Protocol;

/**
 * VoiceBot Sync Protocol v2 — wire constants. Mirrors the backend contract
 * (apps/api/src/voicebot/modules/ingest) and the WordPress reference producer.
 */
final class Protocol
{
    /**
     * The wire gate REQUIRES "2" on every request. The /pair response advertises
     * protocol_version=1 — that field is stale; never read it. Sending anything
     * but "2" is rejected with 426 protocol_upgrade_required.
     */
    public const VERSION = '2';

    public const PATH_PAIR = '/api/v1/ingest/pair';

    public const PATH_INIT = '/api/v1/ingest/init';

    public const PATH_FINALIZE = '/api/v1/ingest/finalize';

    public const PATH_EVENTS = '/api/v1/ingest/events';

    public const PATH_STATUS = '/api/v1/ingest/status';

    public const PATH_UNPAIR = '/api/v1/ingest/unpair';

    public const HEADER_TENANT = 'X-VoiceBot-Tenant-Id';

    public const HEADER_TIMESTAMP = 'X-VoiceBot-Timestamp';

    public const HEADER_NONCE = 'X-VoiceBot-Nonce';

    public const HEADER_SIGNATURE = 'X-VoiceBot-Signature';

    public const HEADER_PROTOCOL = 'X-VoiceBot-Protocol-Version';

    public const HEADER_PLUGIN_VER = 'X-VoiceBot-Plugin-Version';

    public const HEADER_SITE_URL = 'X-VoiceBot-Site-Url';

    public const HEADER_IDEMPOTENCY = 'Idempotency-Key';

    public const REPLAY_WINDOW_SECONDS = 300;

    public const NONCE_LENGTH_BYTES = 16;

    public const MAX_BATCH_EVENTS = 500;

    public const MAX_BATCH_BYTES = 5_242_880;

    public const MAX_FULL_BYTES = 209_715_200;

    /** Identifies this producer on the wire (X-VoiceBot-Plugin-Version). */
    public const CLIENT_NAME = 'laravel-sync';

    public const CLIENT_VERSION = '0.1.0';

    public static function pluginVersionHeader(): string
    {
        return self::CLIENT_NAME.'/'.self::CLIENT_VERSION;
    }
}

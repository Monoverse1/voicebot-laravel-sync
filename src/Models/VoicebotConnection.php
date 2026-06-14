<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single paired-connection row. `secret_b64` is encrypted at rest (Laravel
 * Crypt via the `encrypted` cast) — it is never stored in plaintext, never logged,
 * never returned in any response.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $ingest_url
 * @property string $secret_b64
 * @property Carbon|null $paired_at
 */
class VoicebotConnection extends Model
{
    protected $table = 'voicebot_connections';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'secret_b64' => 'encrypted',
        'paired_at' => 'datetime',
    ];

    /** @var list<string> */
    protected $hidden = ['secret_b64'];
}

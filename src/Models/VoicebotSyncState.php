<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-entity-kind delta watermark + last full-snapshot marker.
 *
 * @property int $id
 * @property string $entity_kind
 * @property Carbon|null $watermark
 * @property Carbon|null $last_full_at
 */
class VoicebotSyncState extends Model
{
    protected $table = 'voicebot_sync_state';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'watermark' => 'datetime',
        'last_full_at' => 'datetime',
    ];
}

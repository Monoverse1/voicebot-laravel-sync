<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A delta operation that exhausted its retries. Retained for inspection and a
 * bounded manual/auto replay; never silently dropped.
 *
 * @property int $id
 * @property string $batch_id
 * @property string $entity_kind
 * @property string|null $external_id
 * @property string $op_type
 * @property array<string, mixed>|null $payload
 * @property string $error
 * @property int $attempts
 * @property bool $exhausted
 * @property Carbon|null $failed_at
 */
class VoicebotDeadLetter extends Model
{
    protected $table = 'voicebot_dead_letter';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'exhausted' => 'boolean',
        'failed_at' => 'datetime',
    ];
}

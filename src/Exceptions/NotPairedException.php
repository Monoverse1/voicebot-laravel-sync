<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Exceptions;

final class NotPairedException extends VoicebotSyncException
{
    public static function make(): self
    {
        return new self('VoiceBot is not paired. Run `php artisan voicebot:pair <code>` first.');
    }
}

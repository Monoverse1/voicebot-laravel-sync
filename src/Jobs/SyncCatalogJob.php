<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Monoverse\VoicebotSync\Sync\SyncRunner;

/**
 * Runs a sync off the request path. `voicebot:sync --queue` dispatches this so a
 * large snapshot/delta never blocks a web request. Shares SyncRunner with the
 * command, so queued and synchronous runs take the exact same path.
 */
final class SyncCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public bool $full = false)
    {
        /** @var string|null $connection */
        $connection = config('voicebot.sync.queue');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function handle(SyncRunner $runner): void
    {
        if ($this->full) {
            $runner->full(false);

            return;
        }
        $runner->delta(false);
    }
}

<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Commands;

use Illuminate\Console\Command;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Support\SecretStore;
use Throwable;

/**
 * Disconnect from VoiceBot: tell the backend to drop the connection, then wipe the
 * locally stored tenant id + shared secret. The local secret is always cleared (even
 * if the remote call fails) so the operator can re-pair instead of being stuck.
 */
final class UnpairCommand extends Command
{
    protected $signature = 'voicebot:unpair';

    protected $description = 'Disconnect this app from VoiceBot and forget the shared secret';

    public function handle(IngestClient $client, SecretStore $secrets): int
    {
        if (! $secrets->isPaired()) {
            $this->warn('Not paired — nothing to do.');

            return self::INVALID;
        }

        $remoteFailed = false;
        try {
            $client->unpair();
        } catch (Throwable $e) {
            $remoteFailed = true;
            $this->warn('Remote unpair failed ('.$e->getMessage().'); clearing local credentials anyway.');
        }

        $secrets->clear();
        $this->info('Unpaired. Local VoiceBot credentials cleared.');

        return $remoteFailed ? self::FAILURE : self::SUCCESS;
    }
}

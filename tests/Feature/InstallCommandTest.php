<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Monoverse\VoicebotSync\Support\SecretStore;

it('migrates and skips pairing when no code is given', function (): void {
    $exit = Artisan::call('voicebot:install', ['--no-interaction' => true]);

    expect($exit)->toBe(0);
    expect(Schema::hasTable('voicebot_connections'))->toBeTrue();
    expect(Schema::hasTable('voicebot_sync_state'))->toBeTrue();
    expect(app(SecretStore::class)->isPaired())->toBeFalse();
});

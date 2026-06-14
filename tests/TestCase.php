<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Tests;

use Monoverse\VoicebotSync\VoiceBotSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Exercise the real consumer install flow: publish the package's .php.stub
        // migrations (datetime-stamped into the app), then migrate.
        $this->artisan('vendor:publish', ['--tag' => 'voicebot-migrations', '--force' => true])->run();
        $this->artisan('migrate')->run();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [VoiceBotSyncServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        // Deterministic key so the `encrypted` cast round-trips inside :memory:.
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('voicebot.base_url', 'https://api.test.local');
        $config->set('voicebot.site_url', 'https://shop.test.local');
        $config->set('voicebot.http.retry.times', 1);
    }
}

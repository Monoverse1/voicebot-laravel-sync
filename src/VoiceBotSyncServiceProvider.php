<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Monoverse\VoicebotSync\Commands\DoctorCommand;
use Monoverse\VoicebotSync\Commands\InstallCommand;
use Monoverse\VoicebotSync\Commands\PairCommand;
use Monoverse\VoicebotSync\Commands\SyncCommand;
use Monoverse\VoicebotSync\Commands\UnpairCommand;
use Monoverse\VoicebotSync\Http\IngestClient;
use Monoverse\VoicebotSync\Sources\SourceResolver;
use Monoverse\VoicebotSync\Support\DeadLetter;
use Monoverse\VoicebotSync\Support\SecretStore;
use Monoverse\VoicebotSync\Support\Watermark;
use Monoverse\VoicebotSync\Sync\DeltaSync;
use Monoverse\VoicebotSync\Sync\FullSync;
use Monoverse\VoicebotSync\Sync\NdjsonStreamWriter;
use Monoverse\VoicebotSync\Sync\SyncRunner;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class VoiceBotSyncServiceProvider extends PackageServiceProvider
{
    private const MIGRATION_FILES = [
        '2025_01_01_000000_create_voicebot_connections_table.php',
        '2025_01_01_000001_create_voicebot_sync_state_table.php',
        '2025_01_01_000002_create_voicebot_dead_letter_table.php',
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('voicebot')
            ->hasConfigFile()
            ->hasCommands([
                InstallCommand::class,
                PairCommand::class,
                UnpairCommand::class,
                SyncCommand::class,
                DoctorCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $dir = __DIR__.'/../database/migrations';
        $this->loadMigrationsFrom($dir);

        $this->registerSchedule();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $map = [];
        foreach (self::MIGRATION_FILES as $file) {
            $map["{$dir}/{$file}"] = database_path("migrations/{$file}");
        }

        $this->publishes($map, 'voicebot-migrations');
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            /** @var Config $config */
            $config = $this->app->make('config');
            if (! $config->get('voicebot.schedule.enabled', false)) {
                return;
            }

            $schedule->command('voicebot:sync')
                ->cron(self::cfgStr($config, 'voicebot.schedule.delta_cron', '*/15 * * * *'))
                ->withoutOverlapping();

            $schedule->command('voicebot:sync --full')
                ->cron(self::cfgStr($config, 'voicebot.schedule.full_cron', '0 3 * * *'))
                ->withoutOverlapping();
        });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SecretStore::class);
        $this->app->singleton(Watermark::class);
        $this->app->singleton(NdjsonStreamWriter::class);

        $this->app->singleton(DeadLetter::class, static function (Container $app): DeadLetter {
            /** @var Config $config */
            $config = $app->make('config');

            return new DeadLetter(self::cfgInt($config, 'voicebot.sync.dead_letter_max_attempts', 5));
        });

        $this->app->singleton(SourceResolver::class, static fn (Container $app): SourceResolver => new SourceResolver($app));

        $this->app->singleton(IngestClient::class, static function (Container $app): IngestClient {
            /** @var Config $config */
            $config = $app->make('config');
            /** @var array<string, mixed> $http */
            $http = $config->get('voicebot.http', []);

            $siteUrl = self::cfgStr($config, 'voicebot.site_url', '');

            return new IngestClient(
                $app->make(SecretStore::class),
                self::cfgStr($config, 'voicebot.base_url', ''),
                $http,
                $siteUrl === '' ? null : $siteUrl,
            );
        });

        $this->app->singleton(FullSync::class, static fn (Container $app): FullSync => new FullSync(
            $app->make(IngestClient::class),
            $app->make(NdjsonStreamWriter::class),
            $app->make(Watermark::class),
        ));

        $this->app->singleton(DeltaSync::class, static function (Container $app): DeltaSync {
            /** @var Config $config */
            $config = $app->make('config');

            return new DeltaSync(
                $app->make(IngestClient::class),
                $app->make(Watermark::class),
                $app->make(DeadLetter::class),
                self::cfgInt($config, 'voicebot.sync.batch_max_ops', 500),
                self::cfgInt($config, 'voicebot.sync.batch_max_bytes', 5_242_880),
            );
        });

        $this->app->singleton(SyncRunner::class, static fn (Container $app): SyncRunner => new SyncRunner(
            $app->make(SourceResolver::class),
            $app->make(FullSync::class),
            $app->make(DeltaSync::class),
            $app->make(Config::class),
        ));
    }

    private static function cfgInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function cfgStr(Config $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }
}

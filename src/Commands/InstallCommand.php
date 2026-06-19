<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Commands;

use Illuminate\Console\Command;
use Monoverse\VoicebotSync\Support\SecretStore;

/**
 * One-command onboarding: publish the config, run the package migrations, and pair.
 * Wires nothing into the host's files — the provider, migrations, and (opt-in)
 * schedule self-register. Pushes no catalog data; that is `voicebot:sync`.
 */
final class InstallCommand extends Command
{
    protected $signature = 'voicebot:install {--key= : Publishable key (pk_...) or legacy pair code (VB-XXXX-XXXX); skips the prompt}';

    protected $description = 'Set up VoiceBot: publish config, migrate, and pair';

    public function handle(SecretStore $secrets): int
    {
        $this->line('VoiceBot install');
        $this->line(str_repeat('-', 40));

        $this->call('vendor:publish', ['--tag' => 'voicebot-config']);
        $this->step('config', 'config/voicebot.php published');

        $this->call('migrate', ['--force' => true]);
        $this->step('migrate', 'voicebot_connections / sync_state / dead_letter migrated');

        if ($secrets->isPaired()) {
            $this->step('pairing', 'already paired (tenant '.$secrets->tenantId().')');
        } elseif (($credential = $this->resolveCredential()) !== null) {
            if ($this->call('voicebot:pair', ['credential' => $credential]) !== self::SUCCESS) {
                $this->error('Pairing failed — fix the error above, then re-run `php artisan voicebot:pair`.');

                return self::FAILURE;
            }
        } else {
            $this->skip('pairing', 'no key — run `php artisan voicebot:pair <pk_...>` when ready');
        }

        $this->newLine();
        $this->info('Next steps');
        $this->line('  1. Map your catalog in config/voicebot.php (enable product/category; set model + map).');
        $this->line('  2. php artisan voicebot:doctor       verify the config maps cleanly.');
        $this->line('  3. php artisan voicebot:sync --full  push the first snapshot.');
        $this->line('  4. Keep it fresh: set VOICEBOT_SCHEDULE_ENABLED=true and run a scheduler');
        $this->line('     (`* * * * * php artisan schedule:run`, or `php artisan schedule:work`).');

        return self::SUCCESS;
    }

    private function resolveCredential(): ?string
    {
        $opt = $this->option('key');
        if (is_string($opt) && trim($opt) !== '') {
            return trim($opt);
        }
        if (! $this->input->isInteractive()) {
            return null;
        }
        $answer = $this->ask('Paste your publishable key (pk_...) or one-time pair code (VB-XXXX-XXXX), or leave blank to pair later');

        return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
    }

    private function step(string $label, string $message): void
    {
        $this->line(sprintf('  <fg=green>OK</>   %-9s %s', $label, $message));
    }

    private function skip(string $label, string $message): void
    {
        $this->line(sprintf('  <fg=yellow>SKIP</> %-9s %s', $label, $message));
    }
}

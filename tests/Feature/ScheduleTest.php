<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/** @return list<string> */
function voicebotScheduledCommands(): array
{
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) ($event->command ?? ''))
        ->filter(fn (string $command): bool => str_contains($command, 'voicebot:sync'))
        ->values()
        ->all();
}

it('registers no sync tasks when the schedule is disabled', function () {
    config()->set('voicebot.schedule.enabled', false);

    expect(voicebotScheduledCommands())->toBeEmpty();
});

it('registers delta and full sync when the schedule is enabled', function () {
    config()->set('voicebot.schedule.enabled', true);
    config()->set('voicebot.schedule.delta_cron', '*/10 * * * *');
    config()->set('voicebot.schedule.full_cron', '30 2 * * *');

    $commands = collect(voicebotScheduledCommands());

    expect($commands)->toHaveCount(2);
    expect($commands->contains(fn (string $c): bool => str_contains($c, 'voicebot:sync --full')))->toBeTrue();
    expect($commands->contains(fn (string $c): bool => str_contains($c, 'voicebot:sync') && ! str_contains($c, '--full')))->toBeTrue();
});

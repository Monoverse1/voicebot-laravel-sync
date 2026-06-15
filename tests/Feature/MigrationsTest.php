<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Proves two properties of the shipped migrations:
//  1. The documented zero-publish flow — a plain `php artisan migrate` (no
//     vendor:publish) creates every package table, because the timestamped .php
//     files are loaded directly via loadMigrationsFrom().
//  2. Publishing is safe — the published copy shares the package migration's exact
//     basename, so the Migrator collapses the two by name and the migration can
//     never double-run (no "table already exists").
it('creates all package tables via a plain migrate with no publish step', function (): void {
    $this->artisan('migrate')->run();

    expect(Schema::hasTable('voicebot_connections'))->toBeTrue()
        ->and(Schema::hasTable('voicebot_sync_state'))->toBeTrue()
        ->and(Schema::hasTable('voicebot_dead_letter'))->toBeTrue();
});

it('creates each table once when migrations are also published', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'voicebot-migrations', '--force' => true])->run();
    $this->artisan('migrate')->run();

    expect(Schema::hasTable('voicebot_connections'))->toBeTrue()
        ->and(Schema::hasTable('voicebot_sync_state'))->toBeTrue()
        ->and(Schema::hasTable('voicebot_dead_letter'))->toBeTrue();
});

it('creates the expected columns on the dead-letter table', function (): void {
    $this->artisan('migrate')->run();

    expect(Schema::hasColumns('voicebot_dead_letter', [
        'batch_id', 'entity_kind', 'external_id', 'op_type', 'payload',
        'error', 'attempts', 'exhausted', 'failed_at',
    ]))->toBeTrue();
});

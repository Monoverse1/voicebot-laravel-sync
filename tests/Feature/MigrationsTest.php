<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Proves the consumer install flow: the package registers + runs its migrations via
// the service provider (runsMigrations + .php.stub), so a plain `migrate` creates the
// tables. TestCase::setUp ran `artisan migrate` through the real registration path.
it('creates all package tables through the service provider migration path', function (): void {
    expect(Schema::hasTable('voicebot_connections'))->toBeTrue()
        ->and(Schema::hasTable('voicebot_sync_state'))->toBeTrue()
        ->and(Schema::hasTable('voicebot_dead_letter'))->toBeTrue();
});

it('creates the expected columns on the dead-letter table', function (): void {
    expect(Schema::hasColumns('voicebot_dead_letter', [
        'batch_id', 'entity_kind', 'external_id', 'op_type', 'payload',
        'error', 'attempts', 'exhausted', 'failed_at',
    ]))->toBeTrue();
});

<?php

declare(strict_types=1);

use Monoverse\VoicebotSync\Tests\TestCase;

// Every test boots a fresh :memory: database, so each one runs the documented
// zero-publish `php artisan migrate` (no vendor:publish) to create the package tables.
// On the pre-fix code (untimestamped .php.stub dropped by the Migrator) this creates
// nothing, turning the suite red — the honest signal that masking is gone.
uses(TestCase::class)
    ->beforeEach(function (): void {
        $this->artisan('migrate')->run();
    })
    ->in(__DIR__);

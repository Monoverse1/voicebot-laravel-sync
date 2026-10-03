<?php

declare(strict_types=1);

use Monoverse\VoicebotSync\Protocol\Protocol;

it('ships the client version of the top changelog release', function (): void {
    $changelog = file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');
    expect($changelog)->toBeString();

    preg_match('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', (string) $changelog, $match);

    expect($match[1] ?? null)->toBe(Protocol::CLIENT_VERSION);
});

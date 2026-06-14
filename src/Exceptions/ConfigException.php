<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Exceptions;

/**
 * A failure a retry cannot fix: bad config, unresolvable model, empty snapshot,
 * oversize snapshot, an upstream 4xx that is not 429. Commands map this to an
 * invalid-input exit code so cron does not pointlessly retry.
 */
final class ConfigException extends VoicebotSyncException {}

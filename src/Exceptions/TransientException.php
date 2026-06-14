<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Exceptions;

/**
 * A failure that may succeed on a later attempt: network error, upstream 429/5xx,
 * timeout. Commands map this to a transient exit code so cron alerts and retries.
 */
final class TransientException extends VoicebotSyncException {}

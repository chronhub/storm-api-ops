<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * The freeze verbs report instead of guessing, the console's own warnings as a 409: a pause finds
 * nothing to freeze when the row is absent, settled, or already paused; a resume finds nothing to
 * lift when nothing was frozen. Conflict rather than not-found on purpose: the correlation may
 * exist and simply not be in the state the verb requires.
 */
final class SagaPauseRefused extends RuntimeException
{
    public static function nothingToFreeze(string $type, string $correlationId): self
    {
        return new self(sprintf(
            'Nothing frozen: "%s/%s" is absent, settled, or already paused.',
            $type,
            $correlationId,
        ));
    }

    public static function nothingToLift(string $type, string $correlationId): self
    {
        return new self(sprintf(
            'Nothing lifted: "%s/%s" is absent or was not paused.',
            $type,
            $correlationId,
        ));
    }

    public static function typeNotFrozen(string $type): self
    {
        return new self(sprintf('Nothing lifted: workflow type "%s" was not frozen.', $type));
    }
}

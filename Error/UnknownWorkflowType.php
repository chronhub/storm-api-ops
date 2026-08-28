<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * A freeze aimed at a workflow type no registry knows: the ops surface's 404, declared on the
 * resource's `exceptionToStatus`. The store registers a pause row for any string handed to it, so
 * without this refusal a correlation id sent in the type's position installs a row that gates
 * nothing, answers success, and leaves running the saga it was meant to hold.
 */
final class UnknownWorkflowType extends RuntimeException
{
    public static function named(string $type): self
    {
        return new self(sprintf(
            'Unknown workflow type "%s". Registered types are served by GET /_storm/describe?section=workflows.',
            $type,
        ));
    }
}

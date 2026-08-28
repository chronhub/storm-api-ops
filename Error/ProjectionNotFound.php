<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * A mutation aimed at a projection with no checkpoint row: the ops surface's 404, declared on the
 * resource's `exceptionToStatus`. Distinct from the registry's `UnknownProjection`, also a 404
 * there: this one is about the ROW, the console's own `No projection "x".` refusal.
 */
final class ProjectionNotFound extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(sprintf('No projection "%s".', $name));
    }
}

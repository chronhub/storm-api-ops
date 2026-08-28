<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;
use Storm\Projector\Store\ProjectionStatus;

/**
 * A status-control verb refused by the projection state machine, the same refusal the console
 * marker commands print, answered as a 409: the request conflicts with the CURRENT state, and the
 * state may legitimately change, so the caller may retry after acting on it. The predicates live
 * on `ProjectionStatus`, the single source of the state machine; this only names the refusal.
 */
final class ProjectionTransitionRefused extends RuntimeException
{
    public static function of(string $verb, ProjectionStatus $status): self
    {
        $message = sprintf('Cannot "%s" a projection in status "%s".', $verb, $status->value);

        if ($status === ProjectionStatus::Failed) {
            $message .= ' A failed projection recovers via "retry" (resume from the checkpoint) or "reset" (replay from scratch).';
        }

        return new self($message);
    }

    /**
     * The compare-and-set found the row no longer in the state the request was validated against: a
     * worker moved it between the read and the write. Nothing was written, and the caller can retry
     * against the fresh state. Same 409 semantics, a different cause.
     */
    public static function raced(string $verb, string $name): self
    {
        return new self(sprintf(
            'Cannot "%s" projection "%s": its status changed while the request was being applied, so '
            .'nothing was written. Re-read it and retry.',
            $verb,
            $name,
        ));
    }
}

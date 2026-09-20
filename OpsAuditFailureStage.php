<?php

declare(strict_types=1);

namespace Storm\ApiOps;

/**
 * The closed vocabulary of where a best-effort `OpsAuditLog` emission failed.
 */
enum OpsAuditFailureStage: string
{
    /** The `IdentityProvider` could not resolve the current actor; nothing was written. */
    case Identity = 'identity';

    /** The logger refused the record; it is not retried. */
    case Sink = 'sink';
}

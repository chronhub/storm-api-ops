<?php

declare(strict_types=1);

namespace Storm\ApiOps;

/**
 * The in-process event announcing that an `OpsAuditLog` emission was dropped.
 *
 * Dispatched synchronously on the app's event dispatcher; it is neither persisted nor counted. A host
 * listener is required to turn it into an alert, a metric or a log on another channel.
 */
final readonly class OpsAuditDegraded
{
    public function __construct(
        public OpsAuditFailureStage $stage,
    ) {}
}

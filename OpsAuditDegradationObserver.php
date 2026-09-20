<?php

declare(strict_types=1);

namespace Storm\ApiOps;

/**
 * Receives one synchronous signal each time an `OpsAuditLog` emission is dropped.
 *
 * The signal carries the failed stage only: no exception class or message, no actor, subject or
 * correlation, so it can leak neither secrets nor unbounded cardinality. An implementation may
 * throw; the audit contains the failure and never lets it replace the business outcome.
 */
interface OpsAuditDegradationObserver
{
    /**
     * Reports that the audit emission failed at `$stage` and was dropped.
     */
    public function auditDegraded(OpsAuditFailureStage $stage): void;
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The wired `OpsAuditDegradationObserver`: relays each signal as an `OpsAuditDegraded` event.
 *
 * With no host listener the event reaches nobody; delivery beyond the dispatcher is the app's.
 */
final readonly class EventDispatcherOpsAuditDegradationObserver implements OpsAuditDegradationObserver
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {}

    #[Override]
    public function auditDegraded(OpsAuditFailureStage $stage): void
    {
        $this->events->dispatch(new OpsAuditDegraded($stage));
    }
}

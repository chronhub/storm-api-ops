<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Psr\Log\LoggerInterface;
use Storm\Bureau\IdentityProvider;
use Throwable;

/**
 * The mutation audit trail: one structured log record per destructive action, naming who acted,
 * the action, its subject, and how it ended. The actor is the Bureau-resolved `Actor` when the
 * app wires an `IdentityProvider`.
 *
 * Delegated to the app's logging stack on purpose, the same doctrine as the alerts engine: the
 * framework emits the structured fact, routing and retention are ops concerns. A dedicated audit
 * table is not the default. Saga cancels additionally carry their operator reason INTO the event
 * store on `SagaCancelled`, the durable trail where one already exists.
 *
 * BEST-EFFORT BY CONTRACT, and therefore shielded: `record()` sits on the mutation path, in the
 * refusal branches, on the failure arm when an outage interrupts an accepted verb, and right after
 * the applied action, so a throwing logger or identity backend
 * must never replace the business outcome by masking a 404/409 or turning an applied reset into a
 * 500 the caller retries. Observability's fail-open rule, the same the Telemetry ports declare;
 * the swallowed failure is deliberately not re-logged, because the only channel available is the
 * one that just failed. Authorization is the exact opposite: {@see OpsActorGate} reads the
 * identity UNSHIELDED, because an unanswerable backend must fail the request closed, never demote
 * it.
 */
final readonly class OpsAuditLog
{
    public function __construct(
        private LoggerInterface $logger,
        private ?IdentityProvider $identity = null,
    ) {}

    public function record(string $action, string $subject, string $outcome): void
    {
        try {
            $actor = $this->identity?->currentActor();

            $this->logger->info('storm_api_ops mutation', [
                'action' => $action,
                'subject' => $subject,
                'outcome' => $outcome,
                'actor_id' => $actor?->id,
                'actor_type' => $actor?->type,
            ]);
        } catch (Throwable) {
            // dropped on purpose: the audit never owns the outcome
        }
    }
}

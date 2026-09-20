<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Psr\Log\LoggerInterface;
use Storm\Bureau\IdentityProvider;
use Throwable;

/**
 * The audit channel for served reads and mutation outcomes, including refused operations.
 *
 * Each record names the action and its subject, with the outcome and the Bureau-resolved `Actor`
 * when the app wires an `IdentityProvider`.
 *
 * Delegated to the app's logging stack on purpose, the same doctrine as the alerts engine: the
 * framework emits the structured fact, routing and retention are ops concerns. A dedicated audit
 * table is not the default. Saga cancels additionally carry their operator reason INTO the event
 * store on `SagaCancelled`, the durable trail where one already exists.
 *
 * BEST-EFFORT BY CONTRACT, and therefore shielded: `record()` sits on served read paths and in the
 * refusal branches, on the failure arm when an outage interrupts an accepted verb, and right after
 * the applied action, so a throwing logger or identity backend
 * must never replace the business outcome by masking a 404/409 or turning an applied reset into a
 * 500 the caller retries. Observability's fail-open rule, the same the Telemetry ports declare;
 * the swallowed failure is deliberately not re-logged, because the only channel available is the
 * one that just failed. Authorization is the exact opposite: {@see OpsActorGate} reads the
 * identity UNSHIELDED, because an unanswerable backend must fail the request closed, never demote
 * it.
 *
 * A dropped emission is reported once to the optional `OpsAuditDegradationObserver`, itself
 * shielded. A failure raised while that signal is in flight, typically a listener recording again
 * on a sink that is still down, is dropped without a second signal, so reentrance stays bounded.
 */
final class OpsAuditLog
{
    private bool $signaling = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?IdentityProvider $identity = null,
        private readonly ?OpsAuditDegradationObserver $observer = null,
    ) {}

    public function record(string $action, string $subject, string $outcome): void
    {
        try {
            $actor = $this->identity?->currentActor();
        } catch (Throwable) {
            $this->signal(OpsAuditFailureStage::Identity);

            return;
        }

        try {
            $this->logger->info('storm_api_ops mutation', [
                'action' => $action,
                'subject' => $subject,
                'outcome' => $outcome,
                'actor_id' => $actor?->id,
                'actor_type' => $actor?->type,
            ]);
        } catch (Throwable) {
            // never retried: the audit never owns the outcome
            $this->signal(OpsAuditFailureStage::Sink);
        }
    }

    private function signal(OpsAuditFailureStage $stage): void
    {
        $observer = $this->observer;

        if ($observer === null || $this->signaling) {
            return;
        }

        $this->signaling = true;

        try {
            $observer->auditDegraded($stage);
        } catch (Throwable) {
            // a failing observer is contained like the sink it reports on
        } finally {
            $this->signaling = false;
        }
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\Bureau\IdentityProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The fail-closed backstop on the whole ops surface: a request with no owned identity is refused
 * with a 403 BEFORE touching anything, whatever the app's firewall did or forgot. The firewall
 * stays the app's authorization layer of roles, zones and the documented `access_control`; this
 * gate owns the one invariant the package itself claims: the audit trail names WHO acted, so a
 * destructive action without an actor is a contradiction, and a hydrated event payload served to
 * nobody is a leak with no trace.
 *
 * The two halves opt out on their own knobs, since their blast radii differ: a mutation moves
 * state, a read drains it; the ONE misconfiguration the docs warn about, an `access_control`
 * pattern that forgets the `/api` mount, must fail loud on both sides, never loud on writes and
 * silent on reads. The `describe` endpoint stays ungated by design: it serves compiled wiring,
 * never a row, and the build/probe use case must survive an identity outage.
 *
 * Reads the identity UNSHIELDED on purpose, the opposite of {@see OpsAuditLog}: per the
 * `IdentityProvider` contract an unanswerable backend THROWS, and that outage must fail the
 * request closed; never demote authenticated traffic to an anonymous refusal, never let it
 * through. Dev and demo environments without an identity substrate opt out explicitly with
 * `storm_api_ops.allow_anonymous_mutations: true` and `storm_api_ops.allow_anonymous_reads: true`;
 * both default to refusing.
 */
final readonly class OpsActorGate
{
    public function __construct(
        private OpsAuditLog $audit,
        private ?IdentityProvider $identity = null,
        #[Autowire('%storm_api_ops.allow_anonymous_mutations%')]
        private bool $allowAnonymous = false,
        #[Autowire('%storm_api_ops.allow_anonymous_reads%')]
        private bool $allowAnonymousReads = false,
    ) {}

    /**
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     *                                  by declaration on every mutation-bearing resource
     */
    public function assertOwnedIdentity(string $action, string $subject): void
    {
        if ($this->allowAnonymous) {
            return;
        }

        if ($this->identity?->currentActor() !== null) {
            return;
        }

        $this->audit->record($action, $subject, 'refused: anonymous mutation');

        throw AnonymousMutationRefused::for($action, $subject);
    }

    /**
     * The read half of the same backstop, on its own knob: a read serves hydrated rows, event
     * payloads above all, and the module's own audit channel must never stay empty while the
     * store is drained through a forgotten firewall pattern.
     *
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out, a 403 by
     *                              declaration on every gated read resource
     */
    public function assertOwnedIdentityForRead(string $action, string $subject): void
    {
        if ($this->allowAnonymousReads) {
            return;
        }

        if ($this->identity?->currentActor() !== null) {
            return;
        }

        $this->audit->record($action, $subject, 'refused: anonymous read');

        throw AnonymousReadRefused::for($action, $subject);
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\Bureau\Actor;
use Storm\Bureau\IdentityProvider;
use Stringable;

/**
 * The gate is actor PRESENCE, and the tests below say where that stops.
 *
 * It refuses a request with no owned identity, whatever the firewall did or forgot, because the
 * package's own claim is that the audit trail names who acted; a destructive action without an actor
 * is a contradiction and a hydrated payload served to nobody is a leak with no trace.
 *
 * It does NOT authorize, and that boundary is asserted rather than left to prose, because the gate is
 * easy to mistake for an authorization layer and the mistake is silent. Roles, zones and the
 * `access_control` map are the app's layer; duplicating them here would put the same decision in two
 * vocabularies that must then agree forever. The `Actor` this gate reads carries an id and a type and
 * no role at all, so checking one would mean either extending that contract for every implementor or
 * pulling `symfony/security` into a module built without it.
 *
 * @see \Storm\Bureau\Actor what the gate can see of an actor
 */
final class OpsActorGateTest extends TestCase
{
    #[Test]
    public function no_identity_substrate_at_all_refuses_and_audits_the_refusal(): void
    {
        // the fail-closed default: a kernel that never wired an IdentityProvider gets a refusal,
        // not a silently anonymous admin surface, and the refusal itself is on the audit line
        $spy = new class() extends AbstractLogger
        {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $gate = new OpsActorGate(new OpsAuditLog($spy));

        try {
            $gate->assertOwnedIdentity('reset', 'probe_catalog');
            self::fail('an anonymous mutation must be refused');
        } catch (AnonymousMutationRefused) {
        }

        self::assertSame('refused: anonymous mutation', $spy->contexts[0]['outcome']);
    }

    #[Test]
    public function a_wired_provider_with_no_bound_actor_still_refuses(): void
    {
        // wiring the substrate is not the gesture; BINDING an actor is: an unauthenticated
        // request cycle carries null, and null is anonymous whatever services exist around it
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), $this->providerReturning(null));

        $this->expectException(AnonymousMutationRefused::class);

        $gate->assertOwnedIdentity('cancel', 'transfer/c-1');
    }

    #[Test]
    public function a_bound_actor_passes(): void
    {
        $gate = new OpsActorGate(
            new OpsAuditLog(new NullLogger),
            $this->providerReturning(new Actor('ops-1', 'user')),
        );

        $gate->assertOwnedIdentity('pause', 'probe_catalog');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function an_actor_of_any_type_passes_because_this_gate_does_not_authorize(): void
    {
        // The boundary, stated as a test. A customer, a device, a batch job: the gate asks whether the
        // request has an owner, never whether that owner should be here. Reading this as a refusal of
        // non-operators is the misconception the test exists to close, and the sibling assertion above
        // uses an ops-looking id, which is exactly what makes the misreading easy.
        $gate = new OpsActorGate(
            new OpsAuditLog(new NullLogger),
            $this->providerReturning(new Actor('cus-77', 'customer')),
        );

        $gate->assertOwnedIdentity('cancel', 'transfer/c-1');
        $gate->assertOwnedIdentityForRead('aggregate.read', 'account-1');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_explicit_opt_out_lets_an_anonymous_mutation_through(): void
    {
        // dev/demo escape hatch, in so many words: allow_anonymous_mutations bypasses the gate
        // entirely; the app owns that posture, the framework only refuses to default into it
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), identity: null, allowAnonymous: true);

        $gate->assertOwnedIdentity('reset', 'probe_catalog');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_on_its_own_knob(): void
    {
        // the read half of the same backstop: the one misconfiguration the docs warn about must
        // fail as loud on GET as on POST, never drain the store silently; and the mutation opt-out
        // does NOT open the reads, the two knobs owning different blast radii. The refusal itself
        // is on the audit line, like the mutation twin's.
        $spy = new class() extends AbstractLogger
        {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $gate = new OpsActorGate(new OpsAuditLog($spy), identity: null, allowAnonymous: true);

        try {
            $gate->assertOwnedIdentityForRead('events.read', 'account-1');
            self::fail('an anonymous read must be refused');
        } catch (AnonymousReadRefused) {
        }

        self::assertSame('refused: anonymous read', $spy->contexts[0]['outcome']);
    }

    #[Test]
    public function a_bound_actor_passes_the_read_gate(): void
    {
        $gate = new OpsActorGate(
            new OpsAuditLog(new NullLogger),
            $this->providerReturning(new Actor('ops-1', 'user')),
        );

        $gate->assertOwnedIdentityForRead('events.read', 'account-1');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_explicit_read_opt_out_lets_an_anonymous_read_through(): void
    {
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), identity: null, allowAnonymousReads: true);

        $gate->assertOwnedIdentityForRead('streams.read', '*');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[Group('adversarial')]
    public function the_read_opt_out_opens_not_one_mutation(): void
    {
        // the other direction of the same independence, and the dangerous one. Reading the two knobs
        // as a single posture is one token away in the code, `allowAnonymous || allowAnonymousReads`,
        // and an app that opens its reads for a dashboard would silently open cancel, redrive, the
        // fleet-wide freeze and the crypto-shred with them. The sibling above proves the mutation
        // knob leaves reads closed; without this one, the blast-radius argument holds in prose only.
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), identity: null, allowAnonymousReads: true);

        $this->expectException(AnonymousMutationRefused::class);

        $gate->assertOwnedIdentity('cancel', 'transfer/c-1');
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unanswerable_identity_backend_fails_the_request_closed(): void
    {
        // the IdentityProvider contract: an outage THROWS, it never demotes to anonymous. The
        // gate must let that propagate; swallowing it here would turn an IAM outage into either
        // a wrong 403 or, worse, a pass; the audit shield is the opposite boundary on purpose
        $backendDown = new RuntimeException('IAM unreachable');
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), new readonly class($backendDown) implements IdentityProvider
        {
            public function __construct(
                private RuntimeException $outage,
            ) {}

            public function authenticate(mixed $credentials): ?Actor
            {
                return null;
            }

            public function currentActor(): ?Actor
            {
                throw $this->outage;
            }
        });

        $this->expectExceptionObject($backendDown);

        $gate->assertOwnedIdentity('cancel', 'transfer/c-1');
    }

    private function providerReturning(?Actor $actor): IdentityProvider
    {
        return new readonly class($actor) implements IdentityProvider
        {
            public function __construct(
                private ?Actor $actor,
            ) {}

            public function authenticate(mixed $credentials): ?Actor
            {
                return $this->actor;
            }

            public function currentActor(): ?Actor
            {
                return $this->actor;
            }
        };
    }
}

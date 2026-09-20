<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Storm\ApiOps\OpsAuditDegradationObserver;
use Storm\ApiOps\OpsAuditFailureStage;
use Storm\ApiOps\OpsAuditLog;
use Storm\Bureau\Actor;
use Storm\Bureau\IdentityProvider;
use Stringable;

final class OpsAuditLogTest extends TestCase
{
    #[Test]
    public function a_mutation_lands_as_one_structured_record_with_its_actor(): void
    {
        $spy = $this->spyLogger();
        $audit = new OpsAuditLog($spy, $this->identityBoundTo(new Actor('ops-1', 'user')));

        $audit->record('reset', 'probe_catalog', 'applied');

        self::assertSame([[
            'message' => 'storm_api_ops mutation',
            'context' => [
                'action' => 'reset',
                'subject' => 'probe_catalog',
                'outcome' => 'applied',
                'actor_id' => 'ops-1',
                'actor_type' => 'user',
            ],
        ]], $spy->records);
    }

    #[Test]
    public function without_an_identity_substrate_the_record_carries_a_null_actor(): void
    {
        $spy = $this->spyLogger();

        new OpsAuditLog($spy)->record('pause', 'probe_catalog', 'applied');

        self::assertNull($spy->records[0]['context']['actor_id']);
        self::assertNull($spy->records[0]['context']['actor_type']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_throwing_logger_never_reaches_the_caller(): void
    {
        // record() sits in the processors' catch blocks and right after applied mutations: a
        // logging outage replacing the business outcome is the exact failure the shield removes
        $audit = new OpsAuditLog(new class() extends AbstractLogger
        {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('audit sink down');
            }
        });

        $audit->record('reset', 'probe_catalog', 'applied');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[Group('adversarial')]
    public function a_throwing_identity_backend_never_reaches_the_caller(): void
    {
        // the identity read is part of the same best-effort emission: an IAM outage must not
        // turn an applied reset into a 500; authorization reads it unshielded, the audit never
        $audit = new OpsAuditLog($this->spyLogger(), new class() implements IdentityProvider
        {
            public function authenticate(mixed $credentials): ?Actor
            {
                return null;
            }

            public function currentActor(): ?Actor
            {
                throw new RuntimeException('token storage down');
            }
        });

        $audit->record('cancel', 'transfer/c-1', 'applied');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[Group('adversarial')]
    public function it_reports_sink_failure_without_changing_applied_outcome(): void
    {
        $observer = $this->recordingObserver();
        $audit = new OpsAuditLog($this->throwingLogger(), null, $observer);

        $outcome = $this->applyThenAudit($audit);

        self::assertSame('applied', $outcome);
        self::assertSame([OpsAuditFailureStage::Sink], $observer->stages);
    }

    #[Test]
    #[Group('adversarial')]
    public function it_reports_identity_failure_without_changing_applied_outcome(): void
    {
        $spy = $this->spyLogger();
        $observer = $this->recordingObserver();
        $audit = new OpsAuditLog($spy, $this->throwingIdentity(), $observer);

        $outcome = $this->applyThenAudit($audit);

        self::assertSame('applied', $outcome);
        self::assertSame([OpsAuditFailureStage::Identity], $observer->stages);
        self::assertSame([], $spy->records);
    }

    #[Test]
    #[Group('adversarial')]
    public function it_contains_observer_failure_without_breaking_caller(): void
    {
        $observer = new class() implements OpsAuditDegradationObserver
        {
            public int $calls = 0;

            public function auditDegraded(OpsAuditFailureStage $stage): void
            {
                $this->calls++;

                throw new RuntimeException('alert channel down');
            }
        };
        $audit = new OpsAuditLog($this->throwingLogger(), null, $observer);

        $outcome = $this->applyThenAudit($audit);

        self::assertSame('applied', $outcome);
        self::assertSame(1, $observer->calls);
    }

    #[Test]
    public function it_emits_no_signal_on_successful_audit(): void
    {
        $spy = $this->spyLogger();
        $observer = $this->recordingObserver();

        $outcome = $this->applyThenAudit(new OpsAuditLog($spy, $this->identityBoundTo(new Actor('ops-1', 'user')), $observer));

        self::assertSame('applied', $outcome);
        self::assertCount(1, $spy->records);
        self::assertSame([], $observer->stages);
    }

    #[Test]
    #[Group('adversarial')]
    public function it_does_not_retry_logging_when_sink_fails(): void
    {
        $logger = $this->throwingLogger();
        $observer = $this->recordingObserver();

        $this->applyThenAudit(new OpsAuditLog($logger, null, $observer));

        self::assertSame(1, $logger->calls);
        self::assertSame([OpsAuditFailureStage::Sink], $observer->stages);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_listener_recording_again_on_a_failing_sink_is_signaled_once(): void
    {
        // without the in-flight guard this recurses until the stack blows: every nested record
        // fails on the same sink and signals the same listener again
        $logger = $this->throwingLogger();
        $observer = new class() implements OpsAuditDegradationObserver
        {
            public ?OpsAuditLog $audit = null;

            public int $calls = 0;

            public function auditDegraded(OpsAuditFailureStage $stage): void
            {
                $this->calls++;
                $this->audit?->record('alert', 'audit', $stage->value);
            }
        };
        $audit = new OpsAuditLog($logger, null, $observer);
        $observer->audit = $audit;

        $outcome = $this->applyThenAudit($audit);

        self::assertSame('applied', $outcome);
        self::assertSame(1, $observer->calls);
        self::assertSame(2, $logger->calls);

        // the guard is released once the signal returns: the next outage is reported again
        $this->applyThenAudit($audit);

        self::assertSame(2, $observer->calls);
    }

    /**
     * The caller's shape: the mutation is applied, audited, then its outcome answered.
     */
    private function applyThenAudit(OpsAuditLog $audit): string
    {
        $audit->record('reset', 'probe_catalog', 'applied');

        return 'applied';
    }

    /**
     * @return OpsAuditDegradationObserver&object{stages: list<OpsAuditFailureStage>}
     */
    private function recordingObserver(): OpsAuditDegradationObserver
    {
        return new class() implements OpsAuditDegradationObserver
        {
            /** @var list<OpsAuditFailureStage> */
            public array $stages = [];

            public function auditDegraded(OpsAuditFailureStage $stage): void
            {
                $this->stages[] = $stage;
            }
        };
    }

    /**
     * @return AbstractLogger&object{calls: int}
     */
    private function throwingLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger
        {
            public int $calls = 0;

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->calls++;

                throw new RuntimeException('audit sink down');
            }
        };
    }

    /**
     * @return AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>}
     */
    private function spyLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger
        {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function throwingIdentity(): IdentityProvider
    {
        return new class() implements IdentityProvider
        {
            public function authenticate(mixed $credentials): ?Actor
            {
                return null;
            }

            public function currentActor(): ?Actor
            {
                throw new RuntimeException('token storage down');
            }
        };
    }

    private function identityBoundTo(Actor $actor): IdentityProvider
    {
        return new readonly class($actor) implements IdentityProvider
        {
            public function __construct(
                private Actor $actor,
            ) {}

            public function authenticate(mixed $credentials): Actor
            {
                return $this->actor;
            }

            public function currentActor(): Actor
            {
                return $this->actor;
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
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

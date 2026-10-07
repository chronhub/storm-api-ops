<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Storm\ApiOps\Error\OperatorPermissionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\OpsAuthorization;
use Storm\Bureau\Actor;
use Storm\Bureau\IdentityProvider;

final class OpsAuthorizationTest extends TestCase
{
    #[Test]
    #[DataProvider('operations')]
    public function a_refused_permission_is_audited_before_access(bool $read): void
    {
        $actor = new Actor('customer-1', 'customer');
        $identity = $this->identity($actor);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('storm_api_ops mutation', $this->callback(
            static fn (array $context): bool => $context['actor_id'] === 'customer-1'
                && $context['outcome'] === ($read ? 'refused: operator read permission' : 'refused: operator mutation permission'),
        ));
        $policy = $this->createMock(OpsAuthorization::class);
        $policy->expects($this->once())->method($read ? 'canRead' : 'canMutate')->with($actor, 'action', 'subject')->willReturn(false);
        $policy->expects($this->never())->method($read ? 'canMutate' : 'canRead');
        $gate = new OpsActorGate(new OpsAuditLog($logger, $identity), $identity, authorization: $policy);
        $this->expectException(OperatorPermissionRefused::class);

        $this->access($gate, $read);
    }

    #[Test]
    #[DataProvider('operations')]
    public function an_explicit_permission_is_scoped_to_the_operation(bool $read): void
    {
        $actor = new Actor('operator', 'user');
        $policy = $this->createMock(OpsAuthorization::class);
        $policy->expects($this->once())->method($read ? 'canRead' : 'canMutate')->with($actor, 'action', 'subject')->willReturn(true);
        $policy->expects($this->never())->method($read ? 'canMutate' : 'canRead');
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), $this->identity($actor), authorization: $policy);

        $this->access($gate, $read);
    }

    #[Test]
    #[DataProvider('operations')]
    public function permission_backend_failures_propagate_without_access(bool $read): void
    {
        $failure = new RuntimeException('permission backend unavailable');
        $policy = $this->createStub(OpsAuthorization::class);
        $policy->method($read ? 'canRead' : 'canMutate')->willThrowException($failure);
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), $this->identity(new Actor('operator', 'user')), authorization: $policy);
        $this->expectExceptionObject($failure);

        $this->access($gate, $read);
    }

    #[Test]
    #[DataProvider('operations')]
    public function an_explicit_dev_bypass_is_audited_without_consulting_permissions(bool $read): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('storm_api_ops mutation', $this->callback(
            static fn (array $context): bool => $context['outcome'] === ($read ? 'bypassed: anonymous read opt-in' : 'bypassed: anonymous mutation opt-in'),
        ));
        $policy = $this->createMock(OpsAuthorization::class);
        $policy->expects($this->never())->method('canRead');
        $policy->expects($this->never())->method('canMutate');
        $gate = new OpsActorGate(new OpsAuditLog($logger), allowAnonymous: ! $read, allowAnonymousReads: $read, authorization: $policy);

        $this->access($gate, $read);
    }

    #[Test]
    #[DataProvider('operations')]
    public function an_old_grant_is_not_reused_for_the_next_call(bool $read): void
    {
        $policy = $this->createMock(OpsAuthorization::class);
        $policy->expects($this->exactly(2))->method($read ? 'canRead' : 'canMutate')->willReturn(true, false);
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), $this->identity(new Actor('operator', 'user')), authorization: $policy);
        $this->access($gate, $read);
        $this->expectException(OperatorPermissionRefused::class);

        $this->access($gate, $read);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function operations(): iterable
    {
        yield 'read' => [true];
        yield 'mutation' => [false];
    }

    private function identity(Actor $actor): IdentityProvider
    {
        $identity = $this->createStub(IdentityProvider::class);
        $identity->method('currentActor')->willReturn($actor);

        return $identity;
    }

    private function access(OpsActorGate $gate, bool $read): void
    {
        if ($read) {
            $gate->assertOwnedIdentityForRead('action', 'subject');
        } else {
            $gate->assertOwnedIdentity('action', 'subject');
        }
    }
}

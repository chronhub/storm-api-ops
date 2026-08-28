<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\SagaResource;
use Storm\ApiOps\State\SagasProvider;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;

/**
 * The correlation window and its type narrowing, which had no unit suite: the module's mutation
 * field never generated a mutant for this provider.
 */
final class SagasProviderTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_before_the_store_is_touched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('transactional');

        $this->expectException(AnonymousReadRefused::class);

        $this->provider($connection, anonymous: false)->provide(new GetCollection, ['correlationId' => 'corr-9']);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refusal_names_the_correlation_that_was_asked_for(): void
    {
        // an operator reading the log must see WHICH read was refused; a generic message would make
        // two refusals on two correlations indistinguishable
        try {
            $this->provider($this->emptyConnection(), anonymous: false)->provide(new GetCollection, ['correlationId' => 'corr-9']);
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('corr-9', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_missing_correlation_is_named_by_the_wildcard_rather_than_by_a_blank(): void
    {
        // the collection with no id is a legitimate shape of the route; the audit subject must still
        // say something, and an empty string in a log line reads as a bug in the logger
        try {
            $this->provider($this->emptyConnection(), anonymous: false)->provide(new GetCollection);
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('*', $e->getMessage());
        }
    }

    #[Test]
    public function a_correlation_carrying_no_saga_answers_an_empty_list(): void
    {
        // "nothing there" is the answer for a collection, never a refusal
        self::assertSame([], $this->provider($this->emptyConnection())->provide(new GetCollection, ['correlationId' => 'corr-9']));
    }

    #[Test]
    public function an_empty_type_narrows_nothing_instead_of_matching_the_empty_string(): void
    {
        // `?type=` is a blank form field, not a workflow named ''
        self::assertSame([], $this->provider($this->emptyConnection())->provide(new GetCollection, ['correlationId' => 'corr-9'], ['filters' => ['type' => '']]));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_type_that_is_not_a_string_narrows_nothing_rather_than_being_cast(): void
    {
        // `?type[]=transfer` arrives as an array; casting it would compare a workflow name against
        // the word "Array" and answer an empty timeline that reads as a correlation with no saga
        self::assertSame([], $this->provider($this->emptyConnection())->provide(new GetCollection, ['correlationId' => 'corr-9'], ['filters' => ['type' => ['transfer']]]));
    }

    #[Test]
    public function every_snapshot_the_gateway_answers_becomes_a_resource(): void
    {
        // the mapping is a layer boundary and it is invisible to a value assertion: the store's
        // snapshot and the served resource carry the same field names, so only the type discriminates
        // the WHOLE row the gateway reads, not the half this assertion happens to need: a partial
        // fixture answers with PHP warnings about the keys it forgot, and those warnings are the
        // fixture confessing that it is not the shape the store returns
        $rows = [[
            'workflow_type' => 'transfer', 'state_key' => 'await_legs', 'status' => 'running', 'version' => 3,
            'started_at' => null, 'updated_at' => null, 'waived_at' => null, 'generation' => 1,
            'definition_version' => 1, 'retry_total' => 0, 'retries' => null, 'compensations' => null,
            'parent_workflow_type' => null, 'parent_correlation_id' => null, 'root_correlation_id' => null,
            'state_version' => 1, 'vars' => null, 'retimes' => 0,
            'paused_at' => null, 'type_paused' => false, 'paused_reason' => null,
        ]];

        // the gateway pays 1+2N reads per correlation: the instances first, then the timers, outbox
        // and children of each. Only the first answers rows; a fixed list of returns would run out
        // and say nothing about the mapping under test.
        $served = false;
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($connection));
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use ($rows, &$served): array {
            if ($served) {
                return [];
            }

            $served = true;

            return $rows;
        });
        $connection->method('fetchOne')->willReturn(0);

        $sagas = $this->provider($connection)->provide(new GetCollection, ['correlationId' => 'corr-9']);

        // @phpstan-ignore staticMethod.alreadyNarrowedType (the declared list type is no runtime guarantee; UnwrapArrayMap survives every value assertion here, the snapshot and the resource carrying the same field names)
        self::assertContainsOnlyInstancesOf(SagaResource::class, $sagas);
    }

    #[Test]
    public function a_named_type_reaches_the_query_rather_than_being_parsed_and_dropped(): void
    {
        // the narrowing OBSERVED: a provider that read the parameter and never passed it on would
        // answer the same rows for every type, and no assertion on the answer would notice
        $captured = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($connection));
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $this->provider($connection)->provide(new GetCollection, ['correlationId' => 'corr-9'], ['filters' => ['type' => 'transfer']]);

        self::assertContains('transfer', $captured);
        // and the correlation reaches it too: a subject replaced by the empty default would read
        // every saga of the store while the caller believes it asked for one
        self::assertContains('corr-9', $captured);
    }

    private function emptyConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        // a stub must honour the contract it stands in for: `transactional` RUNS its closure, and a
        // double that returned null would make the gateway answer null where it never can
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($connection));
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        return $connection;
    }

    private function provider(Connection $connection, bool $anonymous = true): SagasProvider
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new SagasProvider(
            new SagaInspectionGateway($connection, new WorkflowRegistry),
            new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
        );
    }
}

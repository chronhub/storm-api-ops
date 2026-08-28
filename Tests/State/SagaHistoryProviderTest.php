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
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\SagaHistoryRecordResource;
use Storm\ApiOps\Resource\SagaHistoryResource;
use Storm\ApiOps\State\SagaHistoryProvider;
use Storm\Telemetry\History\WorkflowHistoryStore;

/**
 * The narrowing parameters are this provider's whole behaviour, and it had no unit suite: the
 * module's mutation field never generated a mutant for any of it.
 */
final class SagaHistoryProviderTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_before_the_store_is_touched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->expectException(AnonymousReadRefused::class);

        $this->provider($connection, anonymous: false)->provide(new GetCollection, ['correlationId' => 'corr-9']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_generation_that_is_numeric_but_not_integra_l_is_refused(): void
    {
        // `is_numeric` blesses '1.5', and a cast would serve run 1 to a caller who asked for 1.5
        // while the envelope carries no generation to contradict it. Integral in FORM, not numeric.
        $this->expectException(MalformedQueryParameter::class);
        $this->expectExceptionMessageMatches('/generation/');

        $this->read(['generation' => '1.5']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_generation_of_zero_or_below_is_refused_like_a_word(): void
    {
        // a run is one-based; zero would read as "no filter" and widen to every run of a reused
        // correlation, which is the aggregation this refusal exists to prevent
        foreach (['0', '-1', 'latest'] as $raw) {
            try {
                $this->read(['generation' => $raw]);
                self::fail(sprintf('"%s" must not pass as a generation', $raw));
            } catch (MalformedQueryParameter $e) {
                self::assertStringContainsString('generation', $e->getMessage());
            }
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_generation_that_is_not_scalar_is_refused_by_its_type_name(): void
    {
        // `?generation[]=1` arrives as an array; the message quotes the TYPE rather than casting it
        $this->expectException(MalformedQueryParameter::class);
        $this->expectExceptionMessageMatches('/array/');

        $this->read(['generation' => ['1']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_limit_that_is_not_an_integer_is_refused_rather_than_clamped(): void
    {
        $this->expectException(MalformedQueryParameter::class);
        $this->expectExceptionMessageMatches('/limit/');

        $this->read(['limit' => '2.5']);
    }

    #[Test]
    public function an_absent_limit_takes_the_stores_own_default(): void
    {
        // the default belongs to the store, not to a number repeated here: two copies would drift
        $document = $this->read([]);

        self::assertSame(WorkflowHistoryStore::DEFAULT_LIMIT, $document->limit);
    }

    #[Test]
    public function an_absent_generation_reads_every_run_rather_than_refusing(): void
    {
        // the parameter NARROWS; its absence is a legitimate request and never an error
        $document = $this->read([]);

        self::assertSame('corr-9', $document->correlationId);
    }

    #[Test]
    public function an_empty_type_narrows_nothing_instead_of_matching_the_empty_string(): void
    {
        // `?type=` is a form field left blank, not a workflow named ''; treating it literally would
        // answer an empty timeline and read as "this correlation announced nothing"
        $document = $this->read(['type' => '']);

        self::assertSame([], $document->records);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refusal_names_the_correlation_that_was_asked_for(): void
    {
        // the other half of the wildcard case: a subject that always read "*" would make every
        // refusal look like a read of everything, and an audit trail could not tell them apart
        try {
            $this->provider($this->emptyConnection(), anonymous: false)->provide(new GetCollection, ['correlationId' => 'corr-9']);
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('corr-9', $e->getMessage());
        }
    }

    #[Test]
    public function a_recorded_event_becomes_a_resourc_e_on_the_way_out(): void
    {
        // the mapping is a layer boundary, and the store's record and the served resource carry the
        // same field names: only the type discriminates, as it has three times on this branch
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAllAssociative')->willReturn([[
            'workflow_type' => 'transfer', 'correlation_id' => 'corr-9', 'generation' => 1,
            'event_type' => 'workflow.started', 'payload' => '{}', 'event_id' => 'e1',
            'occurred_at' => '2026-08-23T09:00:00+00:00', 'recorded_at' => '2026-08-23T09:00:01+00:00',
        ]]);

        $document = $this->provider($connection)->provide(new GetCollection, ['correlationId' => 'corr-9']);

        // @phpstan-ignore staticMethod.alreadyNarrowedType (the declared list type is no runtime guarantee; UnwrapArrayMap survives every value assertion, the store record and the resource carrying the same field names)
        self::assertContainsOnlyInstancesOf(SagaHistoryRecordResource::class, $document->records);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_missing_correlation_is_named_by_the_wildcard_in_the_refusal(): void
    {
        // an empty string in an audit line reads as a bug in the logger; the wildcard says "all"
        try {
            $this->provider($this->emptyConnection(), anonymous: false)->provide(new GetCollection);
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('*', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refusal_quotes_the_valu_e_that_was_sent(): void
    {
        // naming the parameter is half an answer: an operator retyping a value needs to see WHICH
        // value was refused, and a message that omits it makes two different typos read alike
        foreach ([['generation' => '1.5'], ['limit' => '2.5']] as $filters) {
            try {
                $this->read($filters);
                self::fail('a fractional value must not pass');
            } catch (MalformedQueryParameter $e) {
                self::assertStringContainsString(array_values($filters)[0], $e->getMessage());
            }
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_type_that_is_not_a_string_narrows_nothing_rather_than_being_cast(): void
    {
        // casting `?type[]=transfer` would compare a workflow name against the word "Array" and
        // answer an empty timeline, which reads as a saga that announced nothing
        self::assertSame([], $this->read(['type' => ['transfer']])->records);
    }

    #[Test]
    public function a_named_type_and_a_generation_reach_the_query(): void
    {
        // the narrowing has to be OBSERVED, not assumed: both parameters exist to change the read,
        // and a provider that parsed them and dropped them would answer the same rows either way
        $captured = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $this->provider($connection)->provide(new GetCollection, ['correlationId' => 'corr-9'], ['filters' => ['type' => 'transfer', 'generation' => '4']]);

        self::assertContains('transfer', $captured);
        self::assertContains(4, $captured);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function read(array $filters): SagaHistoryResource
    {
        return $this->provider($this->emptyConnection())->provide(new GetCollection, ['correlationId' => 'corr-9'], ['filters' => $filters]);
    }

    private function emptyConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        return $connection;
    }

    private function provider(Connection $connection, bool $anonymous = true): SagaHistoryProvider
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new SagaHistoryProvider(
            new WorkflowHistoryStore($connection),
            new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
        );
    }
}

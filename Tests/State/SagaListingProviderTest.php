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
use Storm\ApiOps\State\SagaListingProvider;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;

/**
 * The filtered directory the console's `storm:saga:list` twins, and the refusals that keep a
 * narrowing parameter from widening the answer in silence.
 *
 * The gate is asserted HERE and not only through the screen that renders this provider: the JSON
 * route has no controller in front of it, so this call is the whole guard on that channel.
 */
final class SagaListingProviderTest extends TestCase
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $reads = [];

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_before_the_store_is_touched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->expectException(AnonymousReadRefused::class);

        $this->provider($connection, anonymous: false)->provide(new GetCollection);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refusal_names_the_directory_rather_than_a_blank_subject(): void
    {
        // the listing has no subject of its own; an empty string in an audit line reads as a bug in
        // the logger rather than as the read that was refused
        try {
            $this->provider($this->connection(), anonymous: false)->provide(new GetCollection);
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('sagas.read', $e->getMessage());
            self::assertStringContainsString('on "*"', $e->getMessage());
        }
    }

    #[Test]
    public function a_directory_with_no_filter_reads_the_default_window(): void
    {
        $page = $this->provider($this->connection())->provide(new GetCollection);

        self::assertSame([], $page->sagas);
        self::assertFalse($page->truncated);
        self::assertSame(SagaInspectionGateway::DEFAULT_LIMIT, $page->limit);
        self::assertSame(['n'], array_keys($this->lastRead()['params']));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_window_that_is_not_a_positive_integer_is_refused_and_the_message_quotes_it(): void
    {
        // read loosely, `abc` casts to zero and the store answers LIMIT 0: a caller is told the
        // population is empty when nothing was looked at
        try {
            $this->provide(['limit' => 'abc']);
            self::fail('a malformed window must be refused');
        } catch (MalformedQueryParameter $e) {
            self::assertStringContainsString('"limit"', $e->getMessage());
            self::assertStringContainsString('got "abc"', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_window_that_is_not_even_a_scalar_is_named_by_its_type(): void
    {
        // an array arrives from `?limit[]=1`, and a message quoting it would print "Array" or throw
        // inside the very refusal it is writing
        try {
            $this->provide(['limit' => ['1']]);
            self::fail('a malformed window must be refused');
        } catch (MalformedQueryParameter $e) {
            self::assertStringContainsString('got "array"', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function an_idle_cutoff_that_cannot_be_read_is_refused_rather_than_dropped(): void
    {
        // dropped, the request WIDENS: a caller hunting sagas stuck for an hour is served every
        // saga there is, in an envelope that never echoes what was applied
        try {
            $this->provide(['idle_for' => 'soon']);
            self::fail('a malformed cutoff must be refused');
        } catch (MalformedQueryParameter $e) {
            self::assertStringContainsString('"idle_for"', $e->getMessage());
            // the refusal quotes the VALUE, not its type: a caller told its cutoff was a "string"
            // learns nothing about which of its parameters to fix
            self::assertStringContainsString('got "soon"', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_fraction_is_quoted_as_itself_in_the_refusal(): void
    {
        // the declared schema passes a fraction, so this is what actually arrives when a caller
        // sends `?idle_for=1.5`; the refusal carries it into a message typed `string`, and a value
        // handed over unconverted fails inside the refusal it was writing
        foreach (['limit' => 1.5, 'idle_for' => 2.5] as $parameter => $value) {
            try {
                $this->provide([$parameter => $value]);
                self::fail('a fractional '.$parameter.' must be refused');
            } catch (MalformedQueryParameter $e) {
                self::assertStringContainsString('got "'.$value.'"', $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_absent_idle_cutoff_is_not_a_malformed_one(): void
    {
        // the refusal is guarded on the parameter being PRESENT; without that guard every filterless
        // listing would be refused for a cutoff nobody asked for
        $this->provide(['type' => 'transfer']);

        self::assertStringNotContainsString('make_interval', $this->lastRead()['sql']);
    }

    #[Test]
    public function the_cutoff_reaches_the_read_as_an_integer(): void
    {
        $this->provide(['idle_for' => '900']);

        self::assertSame(900, $this->lastRead()['params']['idle']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_type_is_no_narrowing_at_all(): void
    {
        // an empty string reaching the predicate matches no row, so a caller sending `?type=` would
        // read an empty directory as an absence rather than as a filter it never meant to send
        $this->provide(['type' => '']);

        self::assertSame(['n'], array_keys($this->lastRead()['params']));
    }

    #[Test]
    public function a_named_type_narrows_the_read(): void
    {
        $this->provide(['type' => 'transfer']);

        self::assertSame('transfer', $this->lastRead()['params']['type']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_type_that_is_not_a_string_is_no_narrowing_either(): void
    {
        // `?type[]=x` hands the provider an array; a predicate built from it would fail inside the
        // driver rather than answer the caller
        $this->provide(['type' => ['transfer']]);

        self::assertSame(['n'], array_keys($this->lastRead()['params']));
    }

    #[Test]
    public function a_known_status_narrows_the_read_and_an_unknown_one_does_not(): void
    {
        // the enum constraint that refuses a typo lives in the resource's parameter metadata; this
        // fallback is what a caller invoking the provider directly meets
        $this->provide(['status' => 'running']);
        self::assertSame('running', $this->lastRead()['params']['status']);

        $this->provide(['status' => 'runnin']);
        self::assertSame(['n'], array_keys($this->lastRead()['params']));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_waived_filter_answers_to_each_form_a_query_string_carries(): void
    {
        // a checkbox sends `1`, a JSON client sends true, a hand-typed query sends `true`; a filter
        // honouring one of the three drops the narrowing for the other two without a word
        foreach ([true, 'true', '1'] as $asked) {
            $this->provide(['waived' => $asked]);

            self::assertStringContainsString('i.waived_at IS NOT NULL', $this->lastRead()['sql'], 'the waived filter ignored '.var_export($asked, true));
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function anything_else_leaves_the_waived_filter_alone(): void
    {
        // the half that bites: a filter reading every present value as "yes" would hide every saga
        // whose budget was never waived, from a caller that asked for all of them
        foreach ([false, '0', 'no', ''] as $asked) {
            $this->provide(['waived' => $asked]);

            self::assertStringNotContainsString('i.waived_at IS NOT NULL', $this->lastRead()['sql'], 'the waived filter fired on '.var_export($asked, true));
        }
    }

    #[Test]
    public function a_window_is_read_one_row_over_so_truncation_is_known(): void
    {
        // the flag is what tells a full page from a finished population, there being no cursor to
        // page past the wall
        $rows = [$this->row('c-1'), $this->row('c-2'), $this->row('c-3')];
        $page = $this->provider($this->connection($rows))->provide(new GetCollection, [], ['filters' => ['limit' => '2']]);

        self::assertTrue($page->truncated);
        self::assertCount(2, $page->sagas);
        self::assertSame(3, $this->lastRead()['params']['n']);
    }

    #[Test]
    public function the_rows_come_back_as_listing_resources(): void
    {
        $page = $this->provider($this->connection([$this->row('c-1')]))->provide(new GetCollection);

        self::assertCount(1, $page->sagas);
        self::assertSame('c-1', $page->sagas[0]->correlationId);
        self::assertSame('transfer', $page->sagas[0]->workflowType);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function provide(array $filters): void
    {
        $this->provider($this->connection())->provide(new GetCollection, [], ['filters' => $filters]);
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function lastRead(): array
    {
        self::assertNotSame([], $this->reads, 'the store was never read');

        return $this->reads[count($this->reads) - 1];
    }

    private function provider(Connection $connection, bool $anonymous = true): SagaListingProvider
    {
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), null, allowAnonymousReads: $anonymous);

        return new SagaListingProvider(new SagaInspectionGateway($connection, new WorkflowRegistry), $gate);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function connection(array $rows = []): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params = []) use ($rows): array {
                /** @var array<string, mixed> $params */
                $this->reads[] = ['sql' => $sql, 'params' => $params];

                return $rows;
            },
        );

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $correlationId): array
    {
        return [
            'workflow_type' => 'transfer',
            'correlation_id' => $correlationId,
            'state_key' => 'await_legs',
            'status' => 'running',
            'version' => 3,
            'generation' => 1,
            'definition_version' => 2,
            'retry_total' => 0,
            'started_at' => null,
            'updated_at' => null,
            'waived_at' => null,
            'paused_at' => null,
            'parent_correlation_id' => null,
            'type_paused' => false,
        ];
    }
}

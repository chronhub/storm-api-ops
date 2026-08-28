<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\DescribeProvider;
use Storm\ApiOps\State\SagaHistoryProvider;
use Storm\ApiOps\State\SagasProvider;
use Storm\ApiOps\View\SagaDetailView;
use Storm\ApiOps\View\SagaDetailViewController;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Symfony\Describe\StormDescriptor;
use Storm\Telemetry\History\WorkflowHistoryStore;
use Symfony\Component\HttpFoundation\Request;

final class SagaDetailViewControllerTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_caller_is_refused_even_with_no_correlation_named(): void
    {
        // the empty-input page is still a read of this surface: the gate answers before the prompt,
        // so an unnamed caller never learns what the form looks like
        try {
            $this->controller(anonymous: false)(Request::create('/_storm/view/sagas'));
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            // the subject the gate was given is what the audit line will carry; an empty one and a
            // wildcard say different things about what was asked for
            self::assertStringContainsString('saga.read', $e->getMessage());
            // the empty form has no subject of its own, so the wildcard says what was asked for
            self::assertStringContainsString('on "*"', $e->getMessage());
        }
    }

    #[Test]
    public function no_correlation_renders_the_prompt(): void
    {
        $body = $this->controller()(Request::create('/_storm/view/sagas'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('Name a correlation to inspect', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_form_costs_the_store_nothing(): void
    {
        // a page load with no input must not pay a query: the early exit is the whole point, and
        // without it the form would read the saga store, the describe and the history for nothing
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('transactional');
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->controllerOver($connection, $connection)(Request::create('/_storm/view/sagas'));
    }

    #[Test]
    public function the_correlation_reaches_each_read_and_not_only_the_page(): void
    {
        // parsed and dropped, each read would answer for a different correlation than the one asked
        // for, and the page would look right while describing something else
        $seen = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($connection));
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$seen): array {
                $seen[] = $params;

                return [];
            },
        );

        // the two reads get SEPARATE connections, and that separation is the assertion: sharing one
        // would let the saga read's correlation satisfy a claim about the history read, which is an
        // assertion answering half its own question
        $historySeen = [];
        $historyConnection = $this->createStub(Connection::class);
        // the store probes for its table first; answering zero means "absent" and it returns an
        // empty timeline without ever reading, which would leave this assertion nothing to see
        $historyConnection->method('fetchOne')->willReturn(1);
        $historyConnection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$historySeen): array {
                $historySeen[] = $params;

                return [];
            },
        );

        $this->controllerOver($connection, $historyConnection)(Request::create('/_storm/view/sagas?correlation=corr-9'));

        self::assertContains('corr-9', $this->flatten($seen), 'the saga read did not carry the correlation');
        self::assertContains('corr-9', $this->flatten($historySeen), 'the history read did not carry the correlation');
    }

    #[Test]
    public function the_screen_answers_html(): void
    {
        $response = $this->controller()(Request::create('/_storm/view/sagas'));

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_correlation_with_no_saga_still_renders_its_page(): void
    {
        // the three reads are best-effort halves and the instance is empty here; the page must be a
        // page, not a blank or a 500
        $body = $this->controller()(Request::create('/_storm/view/sagas?correlation=corr-none'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('No saga carries this correlation', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refresh_box_is_clamped_at_both_ends(): void
    {
        self::assertStringNotContainsString('setTimeout', $this->body('?refresh=-5'));
        self::assertStringContainsString('300000', $this->body('?refresh=99999'));
        self::assertStringContainsString('8000', $this->body('?refresh=8'));
        self::assertStringNotContainsString('setTimeout', $this->body('?refresh=abc'));
    }

    #[Test]
    public function a_correlation_carrying_a_saga_juxtaposes_the_three_reads(): void
    {
        // the path every empty case leaves untouched: the instance is found, its TYPE drives the
        // declaration lookup, and the history is asked for. The empty pages proved only that
        // nothing happened.
        $body = $this->controller(saga: true)(Request::create('/_storm/view/sagas?correlation=corr-9'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('transfer', $body);
        self::assertStringContainsString('await_legs', $body);
        // the declaration half answered, even if this installation declares nothing for the type
        self::assertStringContainsString('Declaration unavailable', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_correlation_is_trimmed_before_it_is_traced(): void
    {
        // a value pasted from a log carries its spaces; untrimmed it names a correlation that does
        // not exist, and the page would answer "no saga" for one that is right there
        $body = $this->controller(saga: true)(Request::create('/_storm/view/sagas?correlation=%20corr-9%20'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('value="corr-9"', $body);
        self::assertStringNotContainsString('value=" corr-9 "', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_history_that_throws_costs_a_named_notice_and_not_the_page(): void
    {
        // the halves are best-effort: the instance is what the operator came for, and a store that
        // went missing must be NAMED rather than take the page with it
        $body = $this->controller(saga: true, historyThrows: true)(Request::create('/_storm/view/sagas?correlation=corr-9'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('The history could not be read', $body);
        self::assertStringContainsString('await_legs', $body);
    }

    /**
     * @param  list<array<int|string, mixed>>  $captured
     * @return list<mixed>
     */
    private function flatten(array $captured): array
    {
        return $captured === [] ? [] : array_merge(...array_map(array_values(...), $captured));
    }

    private function sagaConnection(bool $saga): Connection
    {
        $rows = [[
            'workflow_type' => 'transfer', 'state_key' => 'await_legs', 'status' => 'running', 'version' => 3,
            'started_at' => null, 'updated_at' => null, 'waived_at' => null, 'generation' => 1,
            'definition_version' => 1, 'retry_total' => 0, 'retries' => null, 'compensations' => null,
            'parent_workflow_type' => null, 'parent_correlation_id' => null, 'root_correlation_id' => null,
            'state_version' => 1, 'vars' => null, 'retimes' => 0,
            'paused_at' => null, 'type_paused' => false, 'paused_reason' => null,
        ]];

        $served = false;
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($connection));
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use ($saga, $rows, &$served): array {
            if (! $saga || $served) {
                return [];
            }

            $served = true;

            return $rows;
        });

        return $connection;
    }

    private function throwingConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willThrowException(new RuntimeException('the history store is unreachable'));
        $connection->method('fetchOne')->willThrowException(new RuntimeException('the history store is unreachable'));

        return $connection;
    }

    private function body(string $query): string
    {
        $content = $this->controller()(Request::create('/_storm/view/sagas'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    private function controller(bool $anonymous = true, bool $saga = false, bool $historyThrows = false): SagaDetailViewController
    {
        $connection = $this->sagaConnection($saga);

        return $this->controllerOver($connection, $historyThrows ? $this->throwingConnection() : $connection, $anonymous);
    }

    private function controllerOver(Connection $connection, Connection $historyConnection, bool $anonymous = true): SagaDetailViewController
    {
        $audit = new OpsAuditLog(new NullLogger);
        $gate = new OpsActorGate($audit, null, allowAnonymousReads: $anonymous);

        // the real collaborators, built the way the modules' own suites build them: every provider
        // here is final, so the graph is assembled rather than doubled
        return new SagaDetailViewController(
            $gate,
            new SagasProvider(new SagaInspectionGateway($connection, new WorkflowRegistry), $gate),
            new DescribeProvider(new StormDescriptor(
                new ProjectionRegistry,
                $this->createStub(EventTypeMapper::class),
                new WorkflowRegistry,
                [],
                'test',
            )),
            new SagaHistoryProvider(new WorkflowHistoryStore($historyConnection), $gate),
            new SagaDetailView,
        );
    }
}

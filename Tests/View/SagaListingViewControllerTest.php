<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\SagaListingProvider;
use Storm\ApiOps\View\SagaListingView;
use Storm\ApiOps\View\SagaListingViewController;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Symfony\Component\HttpFoundation\Request;

final class SagaListingViewControllerTest extends TestCase
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $reads = [];

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_caller_is_refused_before_its_filters_are_judged(): void
    {
        // the query is deliberately unusable: an unnamed caller must not learn even that much about
        // the surface, so the gate answers ahead of the parse rather than after it
        try {
            $this->controller(anonymous: false)(Request::create('/_storm/view/sagas?status=runnin'));
            self::fail('an unnamed caller must not reach the screen');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('sagas.read', $e->getMessage());
            // the directory has no subject of its own; the wildcard says what was asked for
            self::assertStringContainsString('on "*"', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unknown_status_is_refused_rather_than_read_as_no_filter(): void
    {
        // the enum constraint that turns this into a 422 lives in the JSON resource's parameter
        // metadata, which this route does not carry: left to the provider, a typo widens the
        // listing to everything and the operator scans it believing it narrowed
        $body = $this->body('?status=runnin');

        self::assertStringContainsString('must name a lifecycle status', $body);
        self::assertStringContainsString('running, completed, halted, compensated', $body);
        self::assertStringNotContainsString('<table>', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unknown_query_parameter_is_refused_by_name(): void
    {
        // `sagas` is the directory and `saga` is the instance: a link or a bookmark aimed at one
        // lands on the other carrying a parameter it never reads. Ignored, the arrival would render
        // a whole directory and look like an answer to the question that was asked
        $body = $this->body('?correlation=c-1');

        // read in its escaped form, which is how the page carries it: a refusal quotes back what
        // arrived, so it is one of the least trustworthy strings on the screen
        self::assertStringContainsString('&quot;correlation&quot; query parameter means nothing to this screen', $body);
        self::assertStringNotContainsString('<table>', $body);
    }

    #[Test]
    public function the_refresh_control_is_not_an_unknown_parameter(): void
    {
        // the chrome's own key rides the same query string as the filters; refusing it would break
        // the one control every screen of the surface carries
        $body = $this->body('?refresh=15');

        self::assertStringNotContainsString('means nothing to this screen', $body);
        self::assertStringContainsString('<table>', $body);
        self::assertStringContainsString('15000', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_malformed_window_comes_back_on_the_page_and_not_as_a_failure(): void
    {
        // the JSON twin answers 422 to a script, which is what a script needs; a screen that died
        // on a mistyped box would take away the page its reader came for
        $response = $this->controller()(Request::create('/_storm/view/sagas?limit=abc'));
        $body = $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($body);
        self::assertStringContainsString('must be a positive integer', $body);
        self::assertStringNotContainsString('<table>', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function two_mistakes_are_named_in_one_answer(): void
    {
        // one submission, one verdict: naming the first mistake and stopping costs a round trip per
        // typo, and the second one arrives as a surprise after the first was fixed
        $body = $this->body('?status=runnin&nope=1');

        self::assertStringContainsString('must name a lifecycle status', $body);
        self::assertStringContainsString('&quot;nope&quot; query parameter means nothing', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refused_query_costs_the_store_nothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->controllerOver($connection)(Request::create('/_storm/view/sagas?status=runnin'));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_box_is_an_absent_filter_and_not_an_empty_value(): void
    {
        // the form submits every box it carries: passed on as typed, `limit=` would be refused as a
        // malformed window and the operator would lose the page for having submitted it untouched
        $body = $this->body('?type=&status=&idle_for=&limit=');

        self::assertStringContainsString('<table>', $body);
        self::assertStringNotContainsString('must be a positive integer', $body);
        // the window is the only parameter a filterless read carries; naming the keys rather than
        // grepping the statement is what tells an empty box from a filter that reached it, the
        // clause of a type freeze carrying a `WHERE` of its own on every listing
        self::assertSame(['n'], array_keys($this->lastRead()['params']));
        self::assertSame(SagaInspectionGateway::DEFAULT_LIMIT + 1, $this->lastRead()['params']['n']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_box_holding_only_spaces_is_still_an_empty_box(): void
    {
        // the emptiness test runs on the TRIMMED value, and the page-size box is where that bites:
        // a lone space kept as a value is a window that cannot be read, and the operator loses the
        // screen for having brushed a key
        $body = $this->body('?limit=%20&status=%20');

        self::assertStringContainsString('<table>', $body);
        self::assertStringNotContainsString('must be a positive integer', $body);
        self::assertStringNotContainsString('must name a lifecycle status', $body);
    }

    #[Test]
    public function every_filter_reaches_the_read_and_not_only_the_form(): void
    {
        // a filter parsed and dropped still comes back in its box, so the form proves nothing about
        // what was actually asked of the store
        $this->body('?type=transfer&status=running&idle_for=900&waived=1&limit=10');

        $read = $this->lastRead();

        self::assertSame('transfer', $read['params']['type']);
        self::assertSame('running', $read['params']['status']);
        self::assertSame(900, $read['params']['idle']);
        self::assertSame(11, $read['params']['n']);
        self::assertStringContainsString('i.waived_at IS NOT NULL', $read['sql']);
    }

    #[Test]
    public function the_values_that_were_typed_come_back_in_the_form(): void
    {
        $body = $this->body('?type=transfer&limit=10');

        self::assertStringContainsString('name="type" value="transfer"', $body);
        self::assertStringContainsString('name="limit" value="10"', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_value_is_trimmed_before_it_is_read_and_not_only_before_it_is_shown(): void
    {
        // a type pasted with its trailing space matches no row, and the operator reads an empty
        // directory as an absence rather than as a mistyped filter
        $this->body('?type=%20transfer%20');

        self::assertSame('transfer', $this->lastRead()['params']['type']);
    }

    #[Test]
    public function the_screen_answers_html(): void
    {
        $response = $this->controller()(Request::create('/_storm/view/sagas'));

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_directory_with_no_row_still_renders_its_form(): void
    {
        $body = $this->body('?type=nothing-runs-this', rows: []);

        self::assertStringContainsString('No saga matches these filters', $body);
        self::assertStringContainsString('<form method="get">', $body);
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function lastRead(): array
    {
        self::assertNotSame([], $this->reads, 'the store was never read');

        return $this->reads[count($this->reads) - 1];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    private function body(string $query, ?array $rows = null): string
    {
        $content = $this->controller(rows: $rows ?? [$this->row()])(Request::create('/_storm/view/sagas'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    private function controller(bool $anonymous = true, ?array $rows = null): SagaListingViewController
    {
        return $this->controllerOver($this->connection($rows ?? [$this->row()]), $anonymous);
    }

    private function controllerOver(Connection $connection, bool $anonymous = true): SagaListingViewController
    {
        $audit = new OpsAuditLog(new NullLogger);
        $gate = new OpsActorGate($audit, null, allowAnonymousReads: $anonymous);

        // the real collaborators, the way the sibling screens build them: the provider is final, so
        // the graph is assembled rather than doubled, and the parsing it owns is under test here too
        return new SagaListingViewController(
            $gate,
            new SagaListingProvider(new SagaInspectionGateway($connection, new WorkflowRegistry), $gate),
            new SagaListingView,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function connection(array $rows): Connection
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
    private function row(): array
    {
        return [
            'workflow_type' => 'transfer',
            'correlation_id' => 'c-1',
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

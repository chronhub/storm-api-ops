<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\SagaHistoryRecordResource;
use Storm\ApiOps\Resource\SagaHistoryResource;
use Storm\ApiOps\Resource\SagaResource;
use Storm\ApiOps\Tests\Fixture\StubUrlGenerator;
use Storm\ApiOps\View\SagaDeclaration;
use Storm\ApiOps\View\SagaDetailView;
use Storm\ApiOps\View\ViewPage;

final class SagaDetailViewTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_declared_spawn_never_taken_is_announced_and_named(): void
    {
        // the whole reason the two reads are juxtaposed: in front of a saga that stopped moving, the
        // arc that was promised and never taken is the question. No observed-traffic console can ask
        // it, since they draw only the edges they have seen.
        $html = $this->view()->render(
            'corr-9',
            [$this->saga(children: [['workflow_type' => 'settlement_leg', 'correlation_id' => 'c1', 'status' => 'done']])],
            $this->declaration([
                ['slot' => 'leg', 'workflow' => 'settlement_leg', 'awaited_by' => 'await_legs'],
                ['slot' => 'audit', 'workflow' => 'audit_trail', 'awaited_by' => null],
            ]),
            null,
            [],
            0,
        );

        self::assertStringContainsString('1 declared spawn(s) NEVER taken', $html);
        self::assertStringContainsString('slot audit spawns audit_trail', $html);
    }

    #[Test]
    public function the_page_states_the_limit_of_its_own_comparison(): void
    {
        // the match is on the child WORKFLOW, not the slot, so two spawns of one workflow cannot be
        // told apart from the children. A screen claiming a precision it lacks is worse than one
        // that names its bound.
        $html = $this->view()->render('corr-9', [$this->saga()], $this->declaration([
            ['slot' => 'audit', 'workflow' => 'audit_trail', 'awaited_by' => null],
        ]), null, [], 0);

        self::assertStringContainsString('cannot be told apart from the children alone', $html);
    }

    #[Test]
    public function every_spawn_taken_says_so_without_raising_anything(): void
    {
        $html = $this->view()->render(
            'corr-9',
            [$this->saga(children: [['workflow_type' => 'audit_trail', 'correlation_id' => 'c1', 'status' => 'done']])],
            $this->declaration([['slot' => 'audit', 'workflow' => 'audit_trail', 'awaited_by' => null]]),
            null,
            [],
            0,
        );

        self::assertStringContainsString('every one of them taken', $html);
        self::assertStringNotContainsString('class="degraded"', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unavailable_declaration_costs_a_named_absence_and_not_the_page(): void
    {
        $html = $this->view()->render('corr-9', [$this->saga()], SagaDeclaration::forType(null, 'transfer'), null, [], 0);

        self::assertStringContainsString('Declaration unavailable', $html);
        // the instance half still renders: that is the point of degrading rather than failing
        self::assertStringContainsString('step await_legs', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_history_says_whic_h_silence_is_speaking(): void
    {
        // three different situations: never installed, installed and empty, this saga announced
        // nothing. An operator acts differently on each, and one "no history" would hide that.
        $html = $this->view()->render('corr-9', [$this->saga()], $this->declaration([]), new SagaHistoryResource('corr-9', [], false, 100, 'not_installed'), [], 0);

        self::assertStringContainsString('not_installed', $html);
        self::assertStringContainsString('three different answers', $html);
    }

    #[Test]
    public function a_history_window_that_filled_says_older_records_exist(): void
    {
        $html = $this->view()->render('corr-9', [$this->saga()], $this->declaration([]), new SagaHistoryResource('corr-9', [$this->record()], true, 1, 'has_rows'), [], 0);

        self::assertStringContainsString('was FULL', $html);
        self::assertStringContainsString('workflow.started', $html);
    }

    #[Test]
    public function no_correlation_asks_for_one_rather_than_showing_an_empty_shell(): void
    {
        $html = $this->view()->render('', [], SagaDeclaration::forType(null, ''), null, [], 0);

        self::assertStringContainsString('Name a correlation to inspect', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_correlation_with_no_saga_is_not_the_same_as_one_that_finished(): void
    {
        $html = $this->view()->render('corr-9', [], SagaDeclaration::forType(null, ''), null, [], 0);

        self::assertStringContainsString('which is not the same as one that finished', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_page_offers_no_action_at_all(): void
    {
        // the line the surface refuses to cross: cancel, redrive, pause and resume live on guarded
        // twins, and a detail page inviting the button would be exactly that crossing
        $html = $this->view()->render('corr-9', [$this->saga()], $this->declaration([]), null, [], 0);

        foreach (['cancel', 'redrive', 'pause', 'resume', 'method="post"'] as $verb) {
            self::assertStringNotContainsString($verb, $html);
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stored_value_cannot_carry_markup_into_the_page(): void
    {
        $html = $this->view()->render('corr-9', [$this->saga(timers: [['id' => '<script>x</script>', 'kind' => 'k']])], $this->declaration([]), null, [], 0);

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_title_this_screen_renders_is_the_one_the_navigation_calls_it(): void
    {
        // the navigation matches its entries against the CURRENT title to know which one not to
        // link; a screen whose title drifts from its label links itself and bolds nothing. The pair
        // is where it bites, the directory and the instance being one letter apart in their route
        // names and a whole word apart in their labels
        $urls = new StubUrlGenerator(['storm_view_sagas', 'storm_view_saga']);
        $html = new SagaDetailView(new ViewPage($urls))->render('', [], SagaDeclaration::forType(null, ''), null, [], 0);

        self::assertStringContainsString('<strong>saga detail</strong>', $html);
        self::assertStringContainsString('href="/api/_storm/view/storm_view_sagas"', $html);
    }

    private function view(): SagaDetailView
    {
        return new SagaDetailView;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  list<array<string, mixed>>  $timers
     */
    private function saga(array $children = [], array $timers = []): SagaResource
    {
        return new SagaResource(
            workflowType: 'transfer',
            stateKey: 'await_legs',
            status: 'running',
            version: 3,
            startedAt: '2026-08-23T09:00:00Z',
            retries: [],
            compensations: [],
            timers: $timers,
            outbox: [],
            children: $children,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $spawns
     */
    private function declaration(array $spawns): SagaDeclaration
    {
        return SagaDeclaration::forType([
            'available' => true,
            'reason' => null,
            'definitions' => [['name' => 'transfer', 'version' => 1, 'spawns' => $spawns]],
        ], 'transfer');
    }

    private function record(): SagaHistoryRecordResource
    {
        return new SagaHistoryRecordResource('transfer', 'corr-9', 1, 'workflow.started', [], 'e1', '2026-08-23T09:00:00Z', '2026-08-23T09:00:01Z');
    }
}

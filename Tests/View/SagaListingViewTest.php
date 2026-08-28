<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\SagaListingPageResource;
use Storm\ApiOps\Resource\SagaListingResource;
use Storm\ApiOps\Tests\Fixture\StubUrlGenerator;
use Storm\ApiOps\View\SagaListingView;
use Storm\ApiOps\View\ViewPage;

final class SagaListingViewTest extends TestCase
{
    #[Test]
    public function a_row_carries_the_scalars_an_operator_scans(): void
    {
        $html = new SagaListingView()->render($this->page([$this->saga()]), [], [], 0);

        self::assertStringContainsString('transfer', $html);
        self::assertStringContainsString('c-1', $html);
        self::assertStringContainsString('await_legs', $html);
        self::assertStringContainsString('running', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_held_instance_and_a_held_type_are_not_the_same_flag(): void
    {
        // an operator lifting the wrong freeze watches nothing move: the instance stamp is cleared
        // by resuming this saga, the type freeze only by resuming the whole workflow type
        $html = new SagaListingView()->render($this->page([
            $this->saga(correlationId: 'c-held', stateKey: 'a', pausedAt: '2026-01-01T00:00:00Z'),
            $this->saga(correlationId: 'c-frozen', stateKey: 'b', typePaused: true),
        ]), [], [], 0);

        self::assertStringContainsString('<td class="t">paused</td>', $html);
        self::assertStringContainsString('<td class="t">type paused</td>', $html);
    }

    #[Test]
    public function a_saga_held_both_ways_says_both(): void
    {
        $html = new SagaListingView()->render($this->page([
            $this->saga(pausedAt: '2026-01-01T00:00:00Z', typePaused: true),
        ]), [], [], 0);

        self::assertStringContainsString('<td class="t">paused, type paused</td>', $html);
    }

    #[Test]
    public function a_child_and_a_waived_budget_are_flagged_too(): void
    {
        $html = new SagaListingView()->render($this->page([
            $this->saga(waivedAt: '2026-01-01T00:00:00Z', parentCorrelationId: 'c-parent'),
        ]), [], [], 0);

        self::assertStringContainsString('<td class="t">waived, child</td>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_saga_with_nothing_to_flag_renders_an_empty_cell(): void
    {
        // the fixture carries an update stamp on purpose: the empty cell of a saga never updated
        // renders the same dash, and would answer this assertion from the wrong column
        $html = new SagaListingView()->render($this->page([$this->saga()]), [], [], 0);

        self::assertStringContainsString('<td class="t">—</td>', $html);
        self::assertStringContainsString('2026-08-23T10:00:00Z', $html);
    }

    #[Test]
    public function a_saga_never_updated_says_so_where_its_stamp_would_be(): void
    {
        $html = new SagaListingView()->render($this->page([
            $this->saga(pausedAt: '2026-01-01T00:00:00Z', updatedAt: null),
        ]), [], [], 0);

        self::assertStringContainsString('<td class="t">—</td>', $html);
        self::assertStringContainsString('<td class="t">paused</td>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_full_window_says_so_rather_than_looking_complete(): void
    {
        // the window is the operator's blind spot when it fills: a directory showing its first rows
        // while looking whole hides the very instance being hunted
        $full = new SagaListingView()->render($this->page([$this->saga()], truncated: true), [], [], 0);
        $partial = new SagaListingView()->render($this->page([$this->saga()]), [], [], 0);

        self::assertStringContainsString('is FULL', $full);
        self::assertStringNotContainsString('is FULL', $partial);
        self::assertStringContainsString('was not filled', $partial);
    }

    #[Test]
    public function the_window_printed_is_the_one_that_was_applied(): void
    {
        // the provider clamps a window past the ceiling; printing what was ASKED would tell the
        // operator a page of 5000 was served when 500 was
        $html = new SagaListingView()->render($this->page([$this->saga()], limit: 500), [], [], 0);

        self::assertStringContainsString('window of 500', $html);
    }

    #[Test]
    public function an_empty_listing_says_what_the_emptiness_means(): void
    {
        $html = new SagaListingView()->render($this->page([]), [], [], 0);

        self::assertStringContainsString('No saga matches these filters', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refused_page_renders_no_listing_at_all(): void
    {
        // zero rows beside an error reads as "your filter matched nothing", which is a different
        // answer to a different question
        $html = new SagaListingView()->render(null, ['status' => 'runnin'], ['the status is unknown'], 0);

        self::assertStringContainsString('the status is unknown', $html);
        self::assertStringNotContainsString('<table>', $html);
        self::assertStringNotContainsString('No saga matches these filters', $html);
    }

    #[Test]
    public function the_form_keeps_every_value_that_was_typed(): void
    {
        $html = new SagaListingView()->render(null, [
            'type' => 'transfer',
            'status' => 'running',
            'idle_for' => '900',
            'waived' => '1',
            'limit' => '25',
        ], ['refused'], 30);

        self::assertStringContainsString('name="type" value="transfer"', $html);
        self::assertStringContainsString('name="status" value="running"', $html);
        self::assertStringContainsString('name="idle_for" value="900"', $html);
        self::assertStringContainsString('name="limit" value="25"', $html);
        self::assertStringContainsString('name="refresh" value="30"', $html);
        self::assertStringContainsString('name="waived" value="1" checked', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_box_nobody_filled_comes_back_empty_and_not_defaulted(): void
    {
        // the placeholder shows the server's default without claiming it was requested; a value
        // written into the box would come back on the next submission as a filter nobody chose
        $html = new SagaListingView()->render(null, [], ['refused'], 0);

        self::assertStringContainsString('name="limit" value=""', $html);
        self::assertStringContainsString('name="refresh" value=""', $html);
        self::assertStringNotContainsString('checked', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stored_type_is_escaped_like_every_other_stored_string(): void
    {
        $html = new SagaListingView()->render($this->page([
            $this->saga(workflowType: '<script>alert(1)</script>'),
        ]), [], [], 0);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refusal_is_escaped_too(): void
    {
        // a refusal quotes back what the operator typed, so it carries the least trustworthy string
        // on the page
        $html = new SagaListingView()->render(null, [], ['got "<img src=x onerror=y>"'], 0);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function a_row_links_the_instance_it_names(): void
    {
        $html = $this->linked(['storm_view_saga'])->render($this->page([$this->saga()]), [], [], 0);

        self::assertStringContainsString('href="/api/_storm/view/storm_view_saga?correlation=c-1"', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_correlation_is_encoded_for_the_link_and_escaped_for_the_page(): void
    {
        // the two are different jobs on the same string: an unencoded `&` truncates the parameter,
        // an unescaped one breaks out of the attribute
        $html = $this->linked(['storm_view_saga'])->render($this->page([
            $this->saga(correlationId: 'a b&c'),
        ]), [], [], 0);

        self::assertStringContainsString('correlation=a%20b%26c', $html);
        self::assertStringNotContainsString('correlation=a b&c', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_detail_screen_the_application_did_not_import_costs_the_link_and_not_the_row(): void
    {
        // the surface is opt-in per resource: a consumer taking the directory alone still gets its
        // rows, and a generator that throws must not take the page with it
        $html = $this->linked(['storm_view_backlog'])->render($this->page([$this->saga()]), [], [], 0);

        // the navigation links what IT can reach on the same page, so the absence is read on the
        // cell rather than on the document
        self::assertStringContainsString('<td class="t">c-1</td>', $html);
        self::assertStringNotContainsString('correlation=c-1', $html);
    }

    #[Test]
    public function a_view_built_without_a_router_still_names_every_row(): void
    {
        $html = new SagaListingView()->render($this->page([$this->saga()]), [], [], 0);

        self::assertStringContainsString('<td class="t">c-1</td>', $html);
        self::assertStringNotContainsString('<a href', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_title_this_screen_renders_is_the_one_the_navigation_calls_it(): void
    {
        // the navigation matches its entries against the CURRENT title to know which one not to
        // link; a screen whose title drifts from its label links itself and bolds nothing
        $html = $this->linked(['storm_view_sagas', 'storm_view_saga'])->render($this->page([]), [], [], 0);

        self::assertStringContainsString('<strong>sagas</strong>', $html);
        self::assertStringContainsString('href="/api/_storm/view/storm_view_saga"', $html);
    }

    /**
     * @param  list<string>  $known
     */
    private function linked(array $known): SagaListingView
    {
        $urls = new StubUrlGenerator($known);

        return new SagaListingView(new ViewPage($urls), $urls);
    }

    /**
     * @param  list<SagaListingResource>  $sagas
     * @param  positive-int  $limit
     */
    private function page(array $sagas, bool $truncated = false, int $limit = 50): SagaListingPageResource
    {
        return new SagaListingPageResource($sagas, $truncated, $limit);
    }

    private function saga(
        string $workflowType = 'transfer',
        string $correlationId = 'c-1',
        string $stateKey = 'await_legs',
        ?string $pausedAt = null,
        bool $typePaused = false,
        ?string $waivedAt = null,
        ?string $parentCorrelationId = null,
        ?string $updatedAt = '2026-08-23T10:00:00Z',
    ): SagaListingResource {
        return new SagaListingResource(
            workflowType: $workflowType,
            correlationId: $correlationId,
            stateKey: $stateKey,
            status: 'running',
            version: 3,
            generation: 1,
            definitionVersion: 2,
            retryTotal: 0,
            startedAt: null,
            updatedAt: $updatedAt,
            waivedAt: $waivedAt,
            parentCorrelationId: $parentCorrelationId,
            pausedAt: $pausedAt,
            typePaused: $typePaused,
        );
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\StreamResource;
use Storm\ApiOps\Tests\Fixture\StubUrlGenerator;
use Storm\ApiOps\View\StreamsView;
use Storm\ApiOps\View\ViewPage;

final class StreamsViewTest extends TestCase
{
    #[Test]
    public function a_row_names_its_stream_and_how_far_it_is_written(): void
    {
        $html = new StreamsView()->render([new StreamResource('account-1', 7)], '', '', 50, 0);

        self::assertStringContainsString('<td class="t">account-1</td>', $html);
        self::assertStringContainsString('<td class="n">7</td>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_row_opens_nothing(): void
    {
        // the directory is a directory: a stream's events are a payload-bearing read, and the
        // question an operator brings is a correlation, which the trace screen already answers
        $html = new StreamsView()->render([new StreamResource('account-1', 7)], '', '', 50, 0);

        self::assertStringNotContainsString('<td class="t"><a', $html);
        self::assertStringNotContainsString('/events', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_store_and_an_exhausted_window_and_an_empty_lane_say_three_things(): void
    {
        // an operator acts differently on each, and one "no streams" would hide the two that are
        // not about the store at all
        $fresh = new StreamsView()->render([], '', '', 50, 0);
        $past = new StreamsView()->render([], '', 'account-9', 50, 0);
        $lane = new StreamsView()->render([], 'orders', '', 50, 0);

        self::assertStringContainsString('The store holds no stream', $fresh);
        self::assertStringContainsString('No stream past this cursor', $past);
        self::assertStringContainsString('No stream in the &quot;orders&quot; lane', $lane);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_cursor_past_the_end_is_not_read_as_an_empty_lane(): void
    {
        // both narrowings are set on the page an operator reaches by clicking `next` inside a lane;
        // the exhausted window is what it means, and naming the lane instead would send them
        // hunting a category that is perfectly fine
        $html = new StreamsView()->render([], 'orders', 'orders-9', 50, 0);

        self::assertStringContainsString('No stream past this cursor', $html);
        self::assertStringNotContainsString('No stream in the', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_full_window_hands_the_cursor_forward_and_a_partial_one_does_not(): void
    {
        $full = new StreamsView()->render([new StreamResource('a', 1), new StreamResource('b', 2)], '', '', 2, 0);
        $partial = new StreamsView()->render([new StreamResource('a', 1)], '', '', 2, 0);

        self::assertStringContainsString('the window is FULL', $full);
        // the cursor is the LAST name of the page; the first would re-serve everything after it
        self::assertStringContainsString('after=b', $full);
        self::assertStringNotContainsString('after=a', $full);

        self::assertStringContainsString('the whole directory from here on', $partial);
        self::assertStringNotContainsString('<a href', $partial);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_cursor_carries_every_narrowing_the_page_applied(): void
    {
        // a next page that dropped the lane would answer a wider question than the one on screen,
        // and it would do it without a word
        $html = new StreamsView()->render([new StreamResource('orders-1', 1)], 'orders', '', 1, 30);

        self::assertStringContainsString('category=orders', $html);
        self::assertStringContainsString('after=orders-1', $html);
        self::assertStringContainsString('limit=1', $html);
        self::assertStringContainsString('refresh=30', $html);
    }

    #[Test]
    public function a_still_page_hands_no_refresh_to_its_next_window(): void
    {
        // the cursor carries what was APPLIED; a zero written into the link would come back as a
        // refresh setting nobody chose
        $html = new StreamsView()->render([new StreamResource('a', 1)], '', '', 1, 0);

        self::assertStringContainsString('the window is FULL', $html);
        self::assertStringNotContainsString('refresh=', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stream_name_is_encoded_for_the_cursor_and_escaped_for_the_page(): void
    {
        // stream names are stored strings: the two jobs are different, and an unencoded `&` in the
        // cursor truncates it while an unescaped one breaks out of the attribute
        $html = new StreamsView()->render([new StreamResource('a b&c', 1)], '', '', 1, 0);

        self::assertStringContainsString('after=a+b%26c', $html);
        self::assertStringContainsString('<td class="t">a b&amp;c</td>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stored_stream_name_cannot_carry_markup_into_the_page(): void
    {
        $html = new StreamsView()->render([new StreamResource('<script>x</script>', 1)], '', '', 50, 0);

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lane_typed_into_the_empty_notice_is_escaped_too(): void
    {
        // the emptiness quotes back what the operator typed, which makes it the least trustworthy
        // string on a page that otherwise has none
        $html = new StreamsView()->render([], '<img src=x onerror=y>', '', 50, 0);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function the_form_keeps_what_was_applied(): void
    {
        $html = new StreamsView()->render([], 'orders', 'orders-4', 200, 15);

        self::assertStringContainsString('name="category" value="orders"', $html);
        self::assertStringContainsString('name="after" value="orders-4"', $html);
        self::assertStringContainsString('name="limit" value="200"', $html);
        self::assertStringContainsString('name="refresh" value="15"', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_title_this_screen_renders_is_the_one_the_navigation_calls_it(): void
    {
        // the navigation matches its entries against the CURRENT title to know which one not to
        // link; a screen whose title drifts from its label links itself and bolds nothing
        $urls = new StubUrlGenerator(['storm_view_streams', 'storm_view_backlog']);
        $html = new StreamsView(new ViewPage($urls))->render([], '', '', 50, 0);

        self::assertStringContainsString('<strong>streams</strong>', $html);
        self::assertStringContainsString('href="/api/_storm/view/storm_view_backlog"', $html);
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\OutboxFailedResource;
use Storm\ApiOps\View\OutboxFailedView;

final class OutboxFailedViewTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_full_window_says_there_may_be_more_and_offers_the_cursor(): void
    {
        // the case this screen exists for: a dead-letter that grew unattended. A table showing its
        // first rows while looking complete would be worse than one showing none.
        $html = new OutboxFailedView()->render([$this->row(7), $this->row(9)], 0, 2, 0);

        self::assertStringContainsString('the window is FULL', $html);
        // the cursor is the LAST id of the page, which is what makes the next window strictly past it
        self::assertStringContainsString('?after=9&amp;limit=2', $html);
    }

    #[Test]
    public function a_partial_window_says_the_list_ends_here(): void
    {
        $html = new OutboxFailedView()->render([$this->row(7)], 0, 50, 0);

        self::assertStringContainsString('the whole dead-letter from here on', $html);
        self::assertStringNotContainsString('the window is FULL', $html);
    }

    #[Test]
    public function an_empty_first_page_says_nothing_was_given_up_on(): void
    {
        $html = new OutboxFailedView()->render([], 0, 50, 0);

        self::assertStringContainsString('no event has been given up on', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_page_past_a_cursor_says_the_list_is_exhausted_not_empty(): void
    {
        // the two empties are different answers: a dead-letter with nothing in it, and a cursor that
        // has run off the end of one that has plenty. An operator acts differently on each.
        $html = new OutboxFailedView()->render([], 41, 50, 0);

        self::assertStringContainsString('past this cursor', $html);
        self::assertStringNotContainsString('no event has been given up on', $html);
    }

    #[Test]
    public function a_row_without_a_recorded_cause_keeps_its_blank(): void
    {
        // an em dash and never a borrowed value: a row given up on without a recorded moment must
        // not read as one that failed at the epoch
        $html = new OutboxFailedView()->render([$this->row(7, error: null, failedAt: null)], 0, 50, 0);

        self::assertStringContainsString('<pre>—</pre>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function no_payload_reaches_the_page(): void
    {
        // v1 is held: an operator triages on the cause and the age, and the content answers a
        // different question while putting stored data on a screen
        $html = new OutboxFailedView()->render([$this->row(7)], 0, 50, 0);

        self::assertStringNotContainsString('payload', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stored_error_cannot_carry_markup_into_the_page(): void
    {
        $html = new OutboxFailedView()->render([$this->row(7, error: '<script>alert(1)</script>')], 0, 50, 0);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function every_row_of_the_page_is_rendered_not_only_the_first(): void
    {
        $html = new OutboxFailedView()->render([$this->row(7, type: 'a.one'), $this->row(9, type: 'a.two')], 0, 50, 0);

        self::assertStringContainsString('a.one', $html);
        self::assertStringContainsString('a.two', $html);
    }

    private function row(int $id, string $type = 'app.something', ?string $error = 'boom', ?string $failedAt = '2026-08-23T10:00:00Z'): OutboxFailedResource
    {
        return new OutboxFailedResource($id, $id * 100, $type, 3, $error, $failedAt);
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\State\PageWindow;

final class PageWindowTest extends TestCase
{
    #[Test]
    public function an_absent_or_unreadable_limit_falls_back_to_the_default(): void
    {
        // the query parameter is caller-supplied, so anything can arrive; what must never happen is
        // an unbounded read, and the default is what stands in when the value says nothing
        $this->assertSame(PageWindow::DEFAULT_LIMIT, PageWindow::limit([]));
        $this->assertSame(PageWindow::DEFAULT_LIMIT, PageWindow::limit(['limit' => 'many']));
        $this->assertSame(PageWindow::DEFAULT_LIMIT, PageWindow::limit(['limit' => null]));
    }

    #[Test]
    public function a_numeric_limit_is_read_as_an_integer(): void
    {
        // it arrives from a URL, so it is a string; the window is arithmetic and needs the number
        $this->assertSame(10, PageWindow::limit(['limit' => '10']));
        $this->assertSame(10, PageWindow::limit(['limit' => 10]));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_server_cap_holds_at_its_exact_bounds(): void
    {
        // both bounds pinned ON the boundary and one step past it: a cap that is off by one is a cap
        // the caller can still talk past, which is the whole reason this class exists
        $this->assertSame(PageWindow::MAX_LIMIT, PageWindow::limit(['limit' => PageWindow::MAX_LIMIT]));
        $this->assertSame(PageWindow::MAX_LIMIT, PageWindow::limit(['limit' => PageWindow::MAX_LIMIT + 1]));
        $this->assertSame(PageWindow::MAX_LIMIT, PageWindow::limit(['limit' => '100000']));

        $this->assertSame(1, PageWindow::limit(['limit' => 1]));
        $this->assertSame(1, PageWindow::limit(['limit' => 0]));
        $this->assertSame(1, PageWindow::limit(['limit' => -5]));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_position_cursor_never_goes_below_the_start_of_the_stream(): void
    {
        // a negative cursor would read BEFORE the first event, which is not a window but a lie about
        // where the caller is; zero is the floor and the value when nothing readable was sent
        $this->assertSame(0, PageWindow::afterPosition([]));
        $this->assertSame(0, PageWindow::afterPosition(['after' => 'later']));
        $this->assertSame(0, PageWindow::afterPosition(['after' => 0]));
        $this->assertSame(0, PageWindow::afterPosition(['after' => -1]));

        $this->assertSame(42, PageWindow::afterPosition(['after' => '42']));
        $this->assertSame(1, PageWindow::afterPosition(['after' => 1]));
    }

    #[Test]
    public function the_stream_cursor_is_taken_only_when_it_is_a_string(): void
    {
        // its counterpart reads a stream NAME, so a numeric cursor is not a smaller value here, it is
        // the wrong kind entirely, and the empty string means "from the beginning"
        $this->assertSame('', PageWindow::afterStream([]));
        $this->assertSame('', PageWindow::afterStream(['after' => 42]));
        $this->assertSame('account-1', PageWindow::afterStream(['after' => 'account-1']));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lane_is_taken_only_when_it_names_one(): void
    {
        // null is what browses EVERY stream, and it is the answer to all three ways of not naming a
        // lane: absent, empty, or a shape the router filled from `?category[]=x`. An empty string
        // reaching the predicate would match nothing and read as a store with no such lane
        $this->assertNull(PageWindow::category([]));
        $this->assertNull(PageWindow::category(['category' => '']));
        $this->assertNull(PageWindow::category(['category' => ['orders']]));

        $this->assertSame('orders', PageWindow::category(['category' => 'orders']));
    }
}

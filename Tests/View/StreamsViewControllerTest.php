<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StreamsProvider;
use Storm\ApiOps\View\StreamsView;
use Storm\ApiOps\View\StreamsViewController;
use Storm\Chronicler\Directory\StreamDirectory;
use Storm\Chronicler\Directory\StreamHead;
use Symfony\Component\HttpFoundation\Request;

final class StreamsViewControllerTest extends TestCase
{
    /** @var list<array{category: string|null, after: string, limit: int}> */
    private array $browses = [];

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_caller_is_refused_before_the_store_is_browsed(): void
    {
        $directory = $this->createMock(StreamDirectory::class);
        $directory->expects($this->never())->method('browse');

        try {
            $this->controllerOver($directory, anonymous: false)(Request::create('/_storm/view/streams'));
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('streams.read', $e->getMessage());
            // the directory has no subject of its own; the wildcard says what was asked for
            self::assertStringContainsString('on "*"', $e->getMessage());
        }
    }

    #[Test]
    public function every_narrowing_reaches_the_browse_and_not_only_the_form(): void
    {
        // a narrowing parsed and dropped still comes back in its box, so the form proves nothing
        // about what was actually asked of the store
        $body = $this->body('?category=orders&after=orders-4&limit=10');

        self::assertSame(['category' => 'orders', 'after' => 'orders-4', 'limit' => 10], $this->lastBrowse());

        // and the other half of the same question: the page must SAY which narrowing it is showing,
        // or the box comes back empty and the cursor under it drops the lane on the next click
        self::assertStringContainsString('name="category" value="orders"', $body);
        self::assertStringContainsString('name="after" value="orders-4"', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_page_with_no_lane_browses_every_stream(): void
    {
        // null is what browses everything; an empty string reaching the predicate would match no
        // lane at all, and the page would read as a store with nothing in it
        $this->body('?category=');

        self::assertNull($this->lastBrowse()['category']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_window_that_cannot_be_read_costs_a_default_and_never_the_screen(): void
    {
        // this window CLAMPS where the saga directory refuses: a keyset browse whose cursor is a
        // name a person pastes, and a mistyped page size must not take away the page they came for
        $body = $this->body('?limit=abc');

        self::assertSame(PageWindow::DEFAULT_LIMIT, $this->lastBrowse()['limit']);
        self::assertStringContainsString('<table>', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_page_echoes_the_window_that_was_served_and_not_the_one_that_was_asked(): void
    {
        // the cap is server-owned: a form echoing 9999 back would invite the operator to believe a
        // window of that size had been applied, and the summary above it would disagree
        $body = $this->body('?limit=9999');

        self::assertSame(PageWindow::MAX_LIMIT, $this->lastBrowse()['limit']);
        self::assertStringContainsString('name="limit" value="'.PageWindow::MAX_LIMIT.'"', $body);
        self::assertStringNotContainsString('9999', $body);
    }

    #[Test]
    public function the_cursor_reaches_the_browse_as_the_name_it_is(): void
    {
        $this->body('?after=account-9');

        self::assertSame('account-9', $this->lastBrowse()['after']);
    }

    #[Test]
    public function the_streams_the_store_answers_are_the_ones_rendered(): void
    {
        $body = $this->body('', [new StreamHead('account-1', 7), new StreamHead('order-2', 3)]);

        self::assertStringContainsString('<td class="t">account-1</td>', $body);
        self::assertStringContainsString('<td class="t">order-2</td>', $body);
    }

    #[Test]
    public function the_screen_answers_html(): void
    {
        $response = $this->controller()(Request::create('/_storm/view/streams'));

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function an_empty_store_still_renders_its_form(): void
    {
        $body = $this->body('', []);

        self::assertStringContainsString('The store holds no stream', $body);
        self::assertStringContainsString('<form method="get">', $body);
    }

    /**
     * @return array{category: string|null, after: string, limit: int}
     */
    private function lastBrowse(): array
    {
        self::assertNotSame([], $this->browses, 'the store was never browsed');

        return $this->browses[count($this->browses) - 1];
    }

    /**
     * @param  list<StreamHead>|null  $heads
     */
    private function body(string $query, ?array $heads = null): string
    {
        $content = $this->controller($heads)(Request::create('/_storm/view/streams'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    /**
     * @param  list<StreamHead>|null  $heads
     */
    private function controller(?array $heads = null): StreamsViewController
    {
        return $this->controllerOver($this->directory($heads ?? [new StreamHead('account-1', 7)]));
    }

    private function controllerOver(StreamDirectory $directory, bool $anonymous = true): StreamsViewController
    {
        $gate = new OpsActorGate(new OpsAuditLog(new NullLogger), null, allowAnonymousReads: $anonymous);

        // the real provider over a stubbed port, the way the sibling screens build theirs: the
        // window arithmetic it owns is under test here too
        return new StreamsViewController(new StreamsProvider($directory, $gate), new StreamsView);
    }

    /**
     * @param  list<StreamHead>  $heads
     */
    private function directory(array $heads): StreamDirectory
    {
        $directory = $this->createStub(StreamDirectory::class);
        $directory->method('browse')->willReturnCallback(
            function (?string $category, string $after, int $limit) use ($heads): array {
                $this->browses[] = ['category' => $category, 'after' => $after, 'limit' => $limit];

                return $heads;
            },
        );

        return $directory;
    }
}

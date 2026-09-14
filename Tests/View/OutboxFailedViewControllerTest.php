<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\OutboxFailedProvider;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\View\OutboxFailedView;
use Storm\ApiOps\View\OutboxFailedViewController;
use Storm\Chronicler\Outbox\OutboxDeadLetter;
use Symfony\Component\HttpFoundation\Request;

final class OutboxFailedViewControllerTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_by_the_provider_the_screen_reuses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->expectException(AnonymousReadRefused::class);

        $this->controller($connection, anonymous: false)(Request::create('/_storm/view/outbox-failed'));
    }

    #[Test]
    public function the_window_the_caller_asked_for_reaches_the_query(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(self::anything(), ['after' => 41, 'limit' => 7], self::anything())
            ->willReturn([]);

        $this->controller($connection)(Request::create('/_storm/view/outbox-failed?after=41&limit=7'));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_page_echoes_the_window_that_was_serve_d_not_the_one_asked_for(): void
    {
        // the clamp lives in the provider; a page printing the raw request would tell an operator it
        // is looking at ten thousand rows while the query fetched two hundred
        $body = $this->body('?limit=10000');

        self::assertStringContainsString(sprintf('value="%d"', PageWindow::MAX_LIMIT), $body);
        self::assertStringNotContainsString('value="10000"', $body);
    }

    #[Test]
    public function the_screen_answers_html_built_from_the_providers_read(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => '7', 'position' => '700', 'type' => 'app.one', 'attempts' => '3', 'last_error' => 'boom', 'failed_at' => '2026-08-23T10:00:00Z'],
        ]);

        $response = $this->controller($connection)(Request::create('/_storm/view/outbox-failed'));
        $body = $response->getContent();

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertIsString($body);
        self::assertStringContainsString('app.one', $body);
        self::assertStringContainsString('boom', $body);
    }

    #[Test]
    #[TestWith([10, 10])]
    #[TestWith([0, 0])]
    #[TestWith([99999, 300])]
    #[TestWith([-1, 0])]
    public function the_next_page_keeps_the_effective_refresh(int $requested, int $effective): void
    {
        $reads = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $params) use (&$reads): array {
            $reads[] = $params;

            return $params['after'] === 0
                ? [['id' => '7', 'position' => '700', 'type' => 'app.one', 'attempts' => '3', 'last_error' => 'boom', 'failed_at' => null]]
                : [];
        });
        $controller = $this->controller($connection);
        $first = (string) $controller(Request::create('/_storm/view/outbox-failed?limit=1&refresh='.$requested))->getContent();
        self::assertSame(1, preg_match('/href="([^" ]+)">next<\/a>/', $first, $matches));
        $next = html_entity_decode($matches[1] ?? '');
        $second = (string) $controller(Request::create('/_storm/view/outbox-failed'.$next))->getContent();
        self::assertSame([['after' => 0, 'limit' => 1], ['after' => 7, 'limit' => 1]], $reads);
        self::assertStringContainsString('past this cursor', $second);
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
        self::assertSame($effective, (int) ($query['refresh'] ?? 0));
        if ($effective > 0) {
            self::assertStringContainsString((string) ($effective * 1000), $second);
            self::assertStringContainsString('name="refresh" value="'.$effective.'"', $second);
        } else {
            self::assertStringNotContainsString('setTimeout', $second);
            self::assertStringContainsString('name="refresh" value=""', $second);
        }
    }

    private function body(string $query): string
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $content = $this->controller($connection)(Request::create('/_storm/view/outbox-failed'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    private function controller(Connection $connection, bool $anonymous = true): OutboxFailedViewController
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new OutboxFailedViewController(
            new OutboxFailedProvider(
                new OutboxDeadLetter($connection),
                new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
            ),
            new OutboxFailedView,
        );
    }
}

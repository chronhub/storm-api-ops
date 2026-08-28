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
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\OutboxFailedResource;
use Storm\ApiOps\State\OutboxFailedProvider;
use Storm\ApiOps\State\PageWindow;
use Storm\Chronicler\Outbox\OutboxDeadLetter;

final class OutboxFailedProviderTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_before_the_database_is_touched(): void
    {
        // the gate is the first statement for a reason: a refused caller must not cost a query.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->expectException(AnonymousReadRefused::class);

        $this->provider($connection, anonymous: false)->provide(new GetCollection);
    }

    #[Test]
    public function the_callers_window_reaches_the_query(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(self::anything(), ['after' => 41, 'limit' => 7], self::anything())
            ->willReturn([]);

        $this->provider($connection)->provide(new GetCollection, [], ['filters' => ['after' => '41', 'limit' => '7']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_over_cap_limit_is_clamped_before_it_reaches_the_query(): void
    {
        // the declared schema documents the ceiling; this proves the server enforces it, so a caller
        // asking for ten thousand dead-letters gets the cap and not the dead-letter.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(self::anything(), ['after' => 0, 'limit' => PageWindow::MAX_LIMIT], self::anything())
            ->willReturn([]);

        $this->provider($connection)->provide(new GetCollection, [], ['filters' => ['limit' => '10000']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_negative_cursor_cannot_walk_the_page_backwards(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(self::anything(), ['after' => 0, 'limit' => PageWindow::DEFAULT_LIMIT], self::anything())
            ->willReturn([]);

        $this->provider($connection)->provide(new GetCollection, [], ['filters' => ['after' => '-5']]);
    }

    #[Test]
    public function every_row_of_the_page_is_served_not_just_the_first(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => '7', 'position' => '700', 'type' => 'a.one', 'attempts' => '3', 'last_error' => 'boom', 'failed_at' => '2026-08-23T10:00:00Z'],
            ['id' => '9', 'position' => '900', 'type' => 'a.two', 'attempts' => '1', 'last_error' => null, 'failed_at' => null],
        ]);

        $page = $this->provider($connection)->provide(new GetCollection);

        self::assertCount(2, $page);
        // the mapping is the layer boundary, and it is invisible to a field-by-field assertion: the
        // Chronicler row carries the same six property names, so only the TYPE separates the served
        // resource from the store's own DTO handed straight through
        // @phpstan-ignore staticMethod.alreadyNarrowedType (the declared list type is no runtime guarantee; UnwrapArrayMap survived every value assertion because the store DTO carries the same six property names)
        self::assertContainsOnlyInstancesOf(OutboxFailedResource::class, $page);
        self::assertSame(7, $page[0]->id);
        self::assertSame(700, $page[0]->position);
        self::assertSame('a.one', $page[0]->type);
        self::assertSame(3, $page[0]->attempts);
        self::assertSame('boom', $page[0]->lastError);
        self::assertSame('2026-08-23T10:00:00Z', $page[0]->failedAt);
        self::assertSame(9, $page[1]->id);
        self::assertNull($page[1]->lastError);
        self::assertNull($page[1]->failedAt);
    }

    private function provider(Connection $connection, bool $anonymous = true): OutboxFailedProvider
    {
        return new OutboxFailedProvider(
            new OutboxDeadLetter($connection),
            new OpsActorGate(new OpsAuditLog(new NullLogger), null, allowAnonymousReads: $anonymous),
        );
    }
}

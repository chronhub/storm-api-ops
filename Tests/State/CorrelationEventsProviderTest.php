<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\CorrelationEventsProvider;
use Storm\ApiOps\State\CorrelationIdSet;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\ApiOps\Tests\Fixture\StreamedEvent;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Message\Header;
use Storm\Message\Message;

use function array_map;
use function implode;
use function range;

final class CorrelationEventsProviderTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_before_the_set_is_even_parsed(): void
    {
        // the ordering IS the assertion: an unnamed caller must not learn that its set was
        // malformed, so the gate answers first and a blank set still reads 403 and not 422
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $audit = new OpsAuditLog(new NullLogger);
        $provider = new CorrelationEventsProvider($reader, new OpsActorGate($audit, null, allowAnonymousReads: false), $audit);

        $this->expectException(AnonymousReadRefused::class);

        $provider->provide(new GetCollection, [], ['filters' => ['ids' => '  ']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_set_of_nothing_widens_to_the_whole_store_so_it_is_refused(): void
    {
        // dropping a narrowing parameter WIDENS: with no predicate this window would answer every
        // stored event, so an unusable set is a 422 rather than an empty page or a scan
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        foreach ([null, '', '   ', ' , , '] as $raw) {
            try {
                $this->provider($reader)->provide(new GetCollection, [], ['filters' => ['ids' => $raw]]);
                self::fail('an unusable set must never reach the reader');
            } catch (MalformedQueryParameter $e) {
                self::assertStringContainsString('ids', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_set_reaches_the_filter_trimmed_and_in_the_order_given(): void
    {
        $qb = $this->capture(['ids' => 'corr-9, corr-9\x1fkyc ,corr-9\x1fdocs']);

        self::assertSame(['corr-9', 'corr-9\x1fkyc', 'corr-9\x1fdocs'], $qb->getParameter('correlationIds'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_set_at_the_width_ceiling_reaches_the_filter_whole(): void
    {
        // the boundary is where a cap is worth pinning: the whole admitted set must land in the
        // bound parameter, id for id and in order, so nothing is quietly dropped between the parse
        // and the predicate and served as a complete trace
        $ids = $this->ids(CorrelationIdSet::MAX_IDS);

        $qb = $this->capture(['ids' => implode(',', $ids)]);

        self::assertSame($ids, $qb->getParameter('correlationIds'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_set_wider_than_the_ceiling_is_refused_rather_than_narrowed(): void
    {
        // this window answers in JSON and its envelope carries no notice, so a narrowed set would be
        // read as the complete trace: the typed 422 is the only answer the caller can act on
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        try {
            $this->provider($reader)->provide(new GetCollection, [], [
                'filters' => ['ids' => implode(',', $this->ids(CorrelationIdSet::MAX_IDS + 1))],
            ]);
            self::fail('a set wider than the ceiling must never reach the reader');
        } catch (MalformedQueryParameter $e) {
            self::assertStringContainsString('at most '.CorrelationIdSet::MAX_IDS, $e->getMessage());
            self::assertStringContainsString('got '.(CorrelationIdSet::MAX_IDS + 1), $e->getMessage());
        }
    }

    #[Test]
    public function the_window_reads_through_the_stores_own_correlation_filter(): void
    {
        // the predicate is not this provider's to own: it belongs to the layer that ships the index
        $captured = null;
        $reader = $this->reader(static function (QueryFilter $filter) use (&$captured): Generator {
            $captured = $filter;

            yield from [];
        });

        $this->provider($reader)->provide(new GetCollection, [], ['filters' => ['ids' => 'corr-9']]);

        self::assertInstanceOf(CorrelationFeedFilter::class, $captured);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_over_cap_limit_is_clamped_before_it_reaches_the_query(): void
    {
        $qb = $this->capture(['ids' => 'corr-9', 'limit' => '10000']);

        self::assertSame(PageWindow::MAX_LIMIT, $qb->getMaxResults());
    }

    #[Test]
    public function every_event_of_the_trace_is_served_and_the_read_leaves_its_trace(): void
    {
        // two facts one read proves: the whole page is handed back, and a payload-bearing read is
        // recorded, which is the one thing a drained store would otherwise never show
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(
            static function (): Generator {
                yield self::record(1);
                yield self::record(2);
            },
        );

        $log = new RecordingLog;
        $audit = new OpsAuditLog($log);
        $page = new CorrelationEventsProvider($reader, new OpsActorGate($audit, null, allowAnonymousReads: true), $audit)
            ->provide(new GetCollection, [], ['filters' => ['ids' => 'corr-9,corr-4']]);

        self::assertCount(2, $page);
        self::assertSame('account-1', $page[0]->stream);

        self::assertSame('correlations.read', $log->records[0]['context']['action']);
        // the subject is the set as asked, so an audit line replays the exact question
        self::assertSame('corr-9,corr-4', $log->records[0]['context']['subject']);
        self::assertSame('served 2 event(s)', $log->records[0]['context']['outcome']);
    }

    private static function record(int $position): EventRecord
    {
        return new EventRecord(
            new Message(new StreamedEvent, [
                Header::StreamName->key() => 'account-1',
                Header::MessageType->key() => 'App\\SomethingHappened',
            ]),
            SequencePosition::fromInt($position),
            PointInTime::from('2024-01-01T10:00:00.000000+00:00'),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function capture(array $filters): QueryBuilder
    {
        $captured = null;
        $reader = $this->reader(static function (QueryFilter $filter) use (&$captured): Generator {
            $captured = $filter;

            yield from [];
        });

        $this->provider($reader)->provide(new GetCollection, [], ['filters' => $filters]);

        self::assertInstanceOf(QueryFilter::class, $captured, 'the reader must be handed a filter');

        $qb = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ])->createQueryBuilder()->select('e.*')->from('event_store', 'e');

        $captured->apply($qb);

        return $qb;
    }

    /**
     * @return non-empty-list<string>
     */
    private function ids(int $count): array
    {
        return array_map(static fn (int $i): string => 'corr-'.$i, range(1, $count));
    }

    private function reader(callable $onFilter): StreamReader
    {
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback($onFilter);

        return $reader;
    }

    private function provider(StreamReader $reader): CorrelationEventsProvider
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new CorrelationEventsProvider($reader, new OpsActorGate($audit, null, allowAnonymousReads: true), $audit);
    }
}

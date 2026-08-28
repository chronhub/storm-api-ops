<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Get;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\StoredEventResource;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StreamEventsProvider;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\ApiOps\Tests\Fixture\StreamedEvent;
use Storm\Chronicler\Query\MutableQueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Message\Header;
use Storm\Message\Message;

final class StreamEventsProviderTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_stream_path_that_cannot_form_a_name_answers_an_empty_window(): void
    {
        // for a collection, "nothing there" is the answer: a malformed stream path, a hostile URL like
        // /_storm/streams/bad-/events, resolves to a null scope and an empty page, and the reader is
        // never touched, so the null-scope short-circuit is exercised at full fidelity, no fake needed.
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $provider = $this->provider($reader);

        self::assertSame([], $provider->provide(new Get, ['stream' => 'bad-']));  // trailing delimiter
        self::assertSame([], $provider->provide(new Get, ['stream' => '']));      // empty category
        self::assertSame([], $provider->provide(new Get, ['stream' => '9bad']));  // non-canonical category
    }

    #[Test]
    #[Group('adversarial')]
    public function a_malformed_qualified_path_answers_an_empty_window(): void
    {
        // the aggregate-history window, category plus id forming a qualified stream name, takes the same
        // null-scope, empty-page answer when the category or id is malformed; still no reader read.
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $provider = $this->provider($reader);

        self::assertSame([], $provider->provide(new Get, ['category' => '9bad', 'id' => 'x']));
    }

    #[Test]
    public function a_stream_path_scopes_the_read_to_that_whole_category(): void
    {
        // the refusal paths above prove what does NOT reach the reader; this proves what does. The
        // scope is private to the filter, so it is observed where it lands, in the predicate the
        // filter stamps on the query.
        $qb = $this->captureFilter(['stream' => 'account']);

        self::assertStringContainsString('e.category = :feedCategory', $qb->getSQL());
        self::assertStringNotContainsString('e.stream', $qb->getSQL());
        self::assertSame('account', $qb->getParameter('feedCategory'));
    }

    #[Test]
    public function a_qualified_path_narrows_the_read_to_the_one_aggregate_stream(): void
    {
        // category plus id is the aggregate-history window, and it must narrow: the same category
        // predicate PLUS the exact stream, or the window would answer with the neighbours' events.
        $qb = $this->captureFilter(['category' => 'account', 'id' => '1']);

        self::assertStringContainsString('e.stream = :feedStream', $qb->getSQL());
        self::assertSame('account', $qb->getParameter('feedCategory'));
        self::assertSame('account-1', $qb->getParameter('feedStream'));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_callers_window_reaches_the_query_under_the_server_cap(): void
    {
        // the caps are only worth having if they arrive: a window computed and never stamped, or
        // filters read from the wrong place, both leave the read unbounded while the code LOOKS like
        // it bounds it. Asserted where it lands, on the query the reader would run.
        $qb = $this->captureFilter(['stream' => 'account'], ['filters' => ['after' => '7', 'limit' => '5']]);

        self::assertStringContainsString('LIMIT 5', $qb->getSQL());
        self::assertSame(7, $qb->getParameter('feedAfter'));
    }

    #[Test]
    public function an_over_cap_limit_is_clamped_before_it_reaches_the_query(): void
    {
        $qb = $this->captureFilter(['stream' => 'account'], ['filters' => ['limit' => '100000']]);

        self::assertStringContainsString('LIMIT '.PageWindow::MAX_LIMIT, $qb->getSQL());
    }

    #[Test]
    #[Group('adversarial')]
    public function every_event_of_the_page_is_handed_back_not_just_the_first(): void
    {
        // a window that answers with ONE event when the reader yielded several is a page that lies
        // about the stream, and the caller has no way to tell: the positions it never saw simply
        // look like they do not exist
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(
            static function (): Generator {
                yield self::record(1);
                yield self::record(2);
            },
        );

        $page = $this->provider($reader)->provide(new Get, ['stream' => 'account']);

        self::assertCount(2, $page);
        self::assertSame([1, 2], array_map(static fn (StoredEventResource $r): int => $r->position, $page));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_record_whose_headers_say_nothing_still_answers_with_its_own_type(): void
    {
        // headers are what the writer put there, so the read surface cannot assume them: a missing
        // stream reads as unknown rather than as a wrong stream, and the type falls back to the
        // event's own class, which is the one fact the record always carries
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(
            static function (): Generator {
                yield new EventRecord(
                    new Message(new StreamedEvent),
                    SequencePosition::fromInt(1),
                    PointInTime::from('2024-01-01T10:00:00.000000+00:00'),
                );
            },
        );

        $page = $this->provider($reader)->provide(new Get, ['stream' => 'account']);

        self::assertSame('', $page[0]->stream);
        self::assertSame(StreamedEvent::class, $page[0]->type);
    }

    // rig

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
     * @param  array<string, mixed>  $uriVariables
     * @param  array<string, mixed>  $context
     */
    private function captureFilter(array $uriVariables, array $context = []): QueryBuilder
    {
        $captured = null;
        // a stub, not a mock: the assertion is what the filter carries, not that the read happened
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(
            function (MutableQueryFilter $filter) use (&$captured): Generator {
                $captured = $filter;

                yield from [];
            },
        );

        $this->provider($reader)->provide(new Get, $uriVariables, $context);

        self::assertInstanceOf(MutableQueryFilter::class, $captured, 'the reader must be handed the feed filter');

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ]);

        $qb = $connection->createQueryBuilder()->select('e.*')->from('event_store', 'e');
        $captured->apply($qb);

        return $qb;
    }

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_never_reaches_the_reader_and_names_its_subject(): void
    {
        // the gate stands ahead of the store, like every mutation's: an unnamed caller learns
        // nothing, not even whether the stream exists, and the refusal NAMES what was asked
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $audit = new OpsAuditLog(new NullLogger);
        $provider = new StreamEventsProvider($reader, new OpsActorGate($audit, null, allowAnonymousReads: false), $audit);

        try {
            $provider->provide(new Get, ['stream' => 'account-7']);
            self::fail('an unnamed caller must not reach the reader');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('"account-7"', $e->getMessage());
            self::assertStringContainsString('events.read', $e->getMessage());
        }

        // the qualified window names its subject the same way, category and id joined as the
        // stream identity the caller asked for
        try {
            $provider->provide(new Get, ['category' => 'account', 'id' => '42']);
            self::fail('an unnamed caller must not reach the reader on the qualified window either');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('"account-42"', $e->getMessage());
        }
    }

    #[Test]
    public function a_served_page_leaves_an_audit_line_with_its_count(): void
    {
        // the payload-bearing read's trace: hydrated events over HTTP are what a drained store
        // would otherwise never show in the module's own audit channel
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(
            static function (): Generator {
                yield self::record(1);
                yield self::record(2);
            },
        );

        $log = new RecordingLog;
        $audit = new OpsAuditLog($log);
        $page = new StreamEventsProvider($reader, new OpsActorGate($audit, null, allowAnonymousReads: true), $audit)
            ->provide(new Get, ['stream' => 'account']);

        // the record's stored stream rides through; its absence must never read as a wrong stream
        self::assertSame('account-1', $page[0]->stream);

        self::assertSame('events.read', $log->records[0]['context']['action']);
        self::assertSame('account', $log->records[0]['context']['subject']);
        self::assertSame('served 2 event(s)', $log->records[0]['context']['outcome']);
    }

    private function provider(object $reader): StreamEventsProvider
    {
        // the read gate opted out: these tests judge the window and the mapping, not the gate,
        // whose own suite holds the refusal
        $audit = new OpsAuditLog(new NullLogger);

        return new StreamEventsProvider($reader, new OpsActorGate($audit, null, allowAnonymous: false, allowAnonymousReads: true), $audit); // @phpstan-ignore argument.type (the anonymous reader double)
    }
}

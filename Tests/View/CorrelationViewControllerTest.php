<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Doctrine\DBAL\DriverManager;
use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\CorrelationIdSet;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StoredEventResourceFactory;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\ApiOps\Tests\Fixture\StreamedEvent;
use Storm\ApiOps\Tests\Fixture\StubLineage;
use Storm\ApiOps\Tests\Fixture\ThrowingLineage;
use Storm\ApiOps\View\CorrelationLineage;
use Storm\ApiOps\View\CorrelationTraceView;
use Storm\ApiOps\View\CorrelationViewController;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Message\Header;
use Storm\Message\Message;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function array_values;
use function implode;
use function range;
use function sprintf;

final class CorrelationViewControllerTest extends TestCase
{
    #[Test]
    public function an_already_known_child_does_not_hide_the_next_child(): void
    {
        $captured = null;
        $body = $this->body('?ids=corr-9&children=1', new StubLineage(['corr-9', 'corr-new', 'corr-new', 'corr-last']), $captured);
        self::assertSame(['corr-9', 'corr-new', 'corr-last'], $this->correlationIds($captured));
        self::assertStringContainsString('Lineage composed: 3 id(s)', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_caller_is_refused_before_its_input_is_even_read(): void
    {
        // the ordering IS the assertion: a blank set would otherwise render the prompt page, and an
        // unnamed caller would learn that its input was the problem rather than its identity
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $this->expectException(AnonymousReadRefused::class);

        $this->controller($reader, anonymous: false)(Request::create('/_storm/view/correlations'));
    }

    #[Test]
    public function no_set_asks_for_one_instead_of_tracing_everything(): void
    {
        // a missing narrowing parameter must never widen: with no predicate this page would answer
        // the whole store, so the absence is a prompt and the reader is not touched at all
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $body = $this->controller($reader)(Request::create('/_storm/view/correlations'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('Name a correlation id to trace', $body);
    }

    #[Test]
    public function a_set_that_matches_nothing_says_so_rather_than_rendering_a_blank_table(): void
    {
        $body = $this->controller($this->emptyReader())(Request::create('/_storm/view/correlations?ids=corr-9'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('No stored event carries corr-9', $body);
    }

    #[Test]
    public function a_composed_lineage_widens_the_set_and_says_it_did(): void
    {
        $body = $this->body('?ids=corr-9&children=1', new StubLineage(['corr-child', 'corr-grand']));

        // the widened set is echoed into the form, so the operator sees exactly what was queried
        self::assertStringContainsString('value="corr-9,corr-child,corr-grand"', $body);
        self::assertStringContainsString('Lineage composed: 3 id(s)', $body);
    }

    #[Test]
    public function a_correlation_with_no_children_says_the_set_is_the_one_typed(): void
    {
        // an unchanged set after asking to widen is a RESULT, not a silence: without saying so the
        // operator cannot tell a childless saga from a lineage walk that never ran
        $body = $this->body('?ids=corr-9&children=1', new StubLineage([]));

        self::assertStringContainsString('No child correlation was found', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lineage_that_throws_degrades_to_the_typed_set_rather_than_to_a_500(): void
    {
        // the operator still gets the trace it came for, and learns which half broke
        $body = $this->body('?ids=corr-9&children=1', new ThrowingLineage);

        self::assertStringContainsString('The lineage could not be resolved', $body);
        self::assertStringContainsString('value="corr-9"', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lineage_wider_than_the_cap_stops_at_the_cap_and_says_children_were_left_out(): void
    {
        // a screen has no business paging a tree: a saga that fanned out a thousand children would
        // otherwise compose a query as wide as the fan-out, and the page would stop being a trace.
        // A child that exists and did not fit is neither an absent lineage nor a complete one, so
        // the page names the ceiling rather than reporting a composition that looks whole
        $captured = null;
        $body = $this->body('?ids=corr-9&children=1', new StubLineage($this->ids(200, 'corr-child-')), $captured);

        self::assertStringContainsString(
            sprintf('Lineage stopped at %d id(s)', CorrelationViewController::MAX_CHILDREN),
            $body,
        );
        self::assertCount(CorrelationViewController::MAX_CHILDREN, $this->correlationIds($captured));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_composed_lineage_never_passes_the_shared_width_ceiling(): void
    {
        // the bound the store side holds is on the PREDICATE, so a lineage widening the set behind a
        // checkbox must obey the same one a typed set is refused for; the walk's own stop is the
        // lower of the two today, and taking the lesser is what keeps that true if either moves
        $captured = null;
        $ids = $this->ids(CorrelationIdSet::MAX_IDS, 'corr-');
        $body = $this->body('?ids='.implode(',', $ids).'&children=1', new StubLineage(['corr-child']), $captured);

        self::assertSame($ids, $this->correlationIds($captured));
        // the notice names the set it QUERIED, the width here and not the walk's own lower stop: a
        // page naming a ceiling the set printed under it exceeds is one an operator cannot reconcile
        self::assertStringContainsString(sprintf('Lineage stopped at %d id(s)', CorrelationIdSet::MAX_IDS), $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_typed_set_wider_than_the_ceiling_is_narrowed_with_a_notice_rather_than_an_error(): void
    {
        // the screen's half of the shared rule: the JSON window refuses because its envelope carries
        // no notice, and this one has a page to say so on, so the operator keeps the trace and reads
        // exactly how much of the set it covers
        $captured = null;
        $body = $this->body('?ids='.implode(',', $this->ids(CorrelationIdSet::MAX_IDS + 7, 'corr-')), null, $captured);

        self::assertStringContainsString(
            sprintf('Only the first %d of the %d ids named are traced', CorrelationIdSet::MAX_IDS, CorrelationIdSet::MAX_IDS + 7),
            $body,
        );
        self::assertCount(CorrelationIdSet::MAX_IDS, $this->correlationIds($captured));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_typed_set_at_the_ceiling_reaches_the_filter_whole(): void
    {
        // the boundary, where an off-by-one narrowing would show as a trace that looks complete
        $captured = null;
        $ids = $this->ids(CorrelationIdSet::MAX_IDS, 'corr-');
        $body = $this->body('?ids='.implode(',', $ids), null, $captured);

        self::assertSame($ids, $this->correlationIds($captured));
        self::assertStringNotContainsString('Only the first', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_child_already_typed_is_not_queried_twice(): void
    {
        $body = $this->body('?ids=corr-9,corr-child&children=1', new StubLineage(['corr-child']));

        self::assertStringContainsString('value="corr-9,corr-child"', $body);
        self::assertStringContainsString('No child correlation was found', $body);
    }

    #[Test]
    public function the_refresh_box_is_clamped_rather_than_refused(): void
    {
        // a comfort control on a read-only page: a typo there must not cost the operator the trace
        $body = $this->controller($this->emptyReader())(Request::create('/_storm/view/correlations?ids=corr-9&refresh=99999'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('300000', $body); // 300 s, the server cap, in milliseconds
    }

    #[Test]
    public function the_page_answers_as_html(): void
    {
        $response = $this->controller($this->emptyReader())(Request::create('/_storm/view/correlations?ids=corr-9'));

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refresh_clamp_holds_at_both_ends(): void
    {
        // both bounds, because a clamp tested on one side only is half a guard: a negative would
        // reload instantly and a huge one would hammer the store behind the operator's back
        self::assertStringNotContainsString('setTimeout', $this->body('?ids=corr-9&refresh=-5'));
        self::assertStringContainsString('300000', $this->body('?ids=corr-9&refresh=300'));
        self::assertStringContainsString('300000', $this->body('?ids=corr-9&refresh=301'));
        self::assertStringContainsString('7000', $this->body('?ids=corr-9&refresh=7'));
        // a box holding letters is the same answer as an empty one, never a default interval the
        // operator did not choose
        self::assertStringNotContainsString('setTimeout', $this->body('?ids=corr-9&refresh=abc'));
    }

    #[Test]
    public function the_typed_set_is_trimmed_and_blank_segments_are_dropped(): void
    {
        // a trailing or doubled comma is a typing accident; an empty id would widen the trace to
        // rows whose header is absent rather than narrowing it
        $body = $this->body('?ids=+corr-9+,,+corr-4+,');

        self::assertStringContainsString('value="corr-9,corr-4"', $body);
    }

    #[Test]
    #[TestWith([199, false, false])]
    #[TestWith([200, true, false])]
    #[TestWith([200, true, true])]
    public function a_full_window_warns_that_the_trace_may_be_incomplete(int $size, bool $full, bool $wide): void
    {
        $ids = $wide ? $this->ids(CorrelationIdSet::MAX_IDS + 1, 'corr-') : ['corr-9'];
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->once())->method('retrieveByFilter')->with($this->callback(function (QueryFilter $filter) use ($ids): bool {
            self::assertSame(CorrelationIdSet::narrow($ids), $this->correlationIds($filter));

            $qb = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16'])->createQueryBuilder();
            $filter->apply($qb);
            self::assertSame(PageWindow::MAX_LIMIT, $qb->getMaxResults());

            return true;
        }))->willReturnCallback(static function () use ($size): Generator {
            for ($position = 1; $position <= $size; $position++) {
                yield self::record($position, 'app.event');
            }
        });
        $body = (string) $this->controller($reader)(Request::create('/_storm/view/correlations?ids='.implode(',', $ids).'&children=1'))->getContent();
        if ($wide) {
            self::assertStringContainsString('Only the first', $body);
        }
        self::assertStringContainsString(sprintf('%d event(s)', $size), $body);
        self::assertStringContainsString('No child correlation was found', $body);
        $notice = sprintf('The trace reached the %d-event limit and may be incomplete.', PageWindow::MAX_LIMIT);
        if ($full) {
            self::assertStringContainsString($notice, $body);
        } else {
            self::assertStringNotContainsString($notice, $body);
        }
    }

    #[Test]
    public function a_successful_read_audits_the_rendered_count_and_actual_lineage(): void
    {
        $log = new RecordingLog;
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(static function (): Generator {
            yield self::record(1, 'app.first');
            yield self::record(2, 'app.second');
        });
        $response = $this->controller($reader, lineage: new StubLineage(['child']), logger: $log)(Request::create('/_storm/view/correlations?ids=corr-9&children=1'));
        self::assertStringContainsString('app.second', (string) $response->getContent());
        self::assertCount(1, $log->records);
        self::assertSame('correlations.read', $log->records[0]['context']['action']);
        self::assertSame('corr-9,child', $log->records[0]['context']['subject']);
        self::assertIsString($log->records[0]['context']['outcome']);
        self::assertStringContainsString('2 event(s)', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function an_empty_read_audits_only_the_ids_that_reached_the_filter(): void
    {
        $log = new RecordingLog;
        $captured = null;
        $ids = implode(',', $this->ids(CorrelationIdSet::MAX_IDS + 1, 'corr-'));
        $this->controller($this->emptyReader($captured), logger: $log)(Request::create('/_storm/view/correlations?ids='.$ids));
        self::assertCount(1, $log->records);
        self::assertSame(implode(',', $this->correlationIds($captured)), $log->records[0]['context']['subject']);
        self::assertIsString($log->records[0]['context']['outcome']);
        self::assertStringContainsString('0 event(s)', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function the_prompt_without_ids_does_not_claim_a_read(): void
    {
        $log = new RecordingLog;
        $this->controller($this->emptyReader(), logger: $log)(Request::create('/_storm/view/correlations'));
        self::assertSame([], $log->records);
    }

    #[Test]
    public function a_failed_store_read_does_not_log_success(): void
    {
        $log = new RecordingLog;
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willThrowException(new RuntimeException('store unavailable'));
        $this->expectException(RuntimeException::class);
        try {
            $this->controller($reader, logger: $log)(Request::create('/_storm/view/correlations?ids=corr-9'));
        } finally {
            self::assertSame([], $log->records);
        }
    }

    #[Test]
    public function an_unavailable_audit_logger_does_not_break_the_page(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->willThrowException(new RuntimeException('logger unavailable'));
        $response = $this->controller($this->emptyReader(), logger: $logger)(Request::create('/_storm/view/correlations?ids=corr-9'));
        self::assertSame(200, $response->getStatusCode());
    }

    private function body(string $query, ?CorrelationLineage $lineage = null, mixed &$captured = null): string
    {
        $content = $this->controller($this->emptyReader($captured), lineage: $lineage)(Request::create('/_storm/view/correlations'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    /**
     * The set the page actually queried, read off the bound parameter rather than off the rendered
     * form: the echo is what the operator sees, and this is what the store was asked.
     *
     * @return list<string>
     */
    private function correlationIds(mixed $captured): array
    {
        self::assertInstanceOf(CorrelationFeedFilter::class, $captured, 'the reader must be handed the correlation filter');

        $qb = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ])->createQueryBuilder()->select('e.*')->from('event_store', 'e');

        $captured->apply($qb);

        $ids = $qb->getParameter('correlationIds');
        self::assertIsArray($ids);

        return array_values($ids);
    }

    /**
     * @return non-empty-list<string>
     */
    private function ids(int $count, string $prefix): array
    {
        return array_map(static fn (int $i): string => $prefix.$i, range(1, $count));
    }

    #[Test]
    public function every_record_the_reader_yields_reaches_the_page(): void
    {
        // the loop the empty-reader tests never enter: a page that rendered only the first row of a
        // trace would be worse than one that rendered none, because it would look complete
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(static function (): Generator {
            yield self::record(1, 'app.first');
            yield self::record(2, 'app.second');
        });

        $body = $this->controller($reader)(Request::create('/_storm/view/correlations?ids=corr-9'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('app.first', $body);
        self::assertStringContainsString('app.second', $body);
        self::assertStringContainsString('2 event(s)', $body);
    }

    private static function record(int $position, string $type): EventRecord
    {
        return new EventRecord(
            new Message(new StreamedEvent, [
                Header::StreamName->key() => 'account-1',
                Header::MessageType->key() => $type,
            ]),
            SequencePosition::fromInt($position),
            PointInTime::from('2026-08-23T10:00:00.000000+00:00'),
        );
    }

    private function emptyReader(mixed &$captured = null): StreamReader
    {
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(static function (QueryFilter $filter) use (&$captured): Generator {
            $captured = $filter;

            yield from [];
        });

        return $reader;
    }

    private function controller(StreamReader $reader, bool $anonymous = true, ?CorrelationLineage $lineage = null, ?LoggerInterface $logger = null): CorrelationViewController
    {
        $audit = new OpsAuditLog($logger ?? new NullLogger);

        return new CorrelationViewController(
            $reader,
            new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
            new StoredEventResourceFactory,
            new CorrelationTraceView,
            $lineage ?? new StubLineage([]),
            $audit,
        );
    }
}

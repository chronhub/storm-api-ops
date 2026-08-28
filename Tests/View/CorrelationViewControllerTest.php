<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\StoredEventResourceFactory;
use Storm\ApiOps\Tests\Fixture\StreamedEvent;
use Storm\ApiOps\Tests\Fixture\StubLineage;
use Storm\ApiOps\Tests\Fixture\ThrowingLineage;
use Storm\ApiOps\View\CorrelationLineage;
use Storm\ApiOps\View\CorrelationTraceView;
use Storm\ApiOps\View\CorrelationViewController;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Message\Header;
use Storm\Message\Message;
use Symfony\Component\HttpFoundation\Request;

final class CorrelationViewControllerTest extends TestCase
{
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
    public function a_lineage_wider_than_the_cap_stops_at_the_cap(): void
    {
        // a screen has no business paging a tree: a saga that fanned out a thousand children would
        // otherwise compose a query as wide as the fan-out, and the page would stop being a trace
        $body = $this->body('?ids=corr-9&children=1', new StubLineage(array_map(
            static fn (int $i): string => 'corr-child-'.$i,
            range(1, 200),
        )));

        self::assertStringContainsString(
            sprintf('Lineage composed: %d id(s)', CorrelationViewController::MAX_CHILDREN),
            $body,
        );
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

    private function body(string $query, ?CorrelationLineage $lineage = null): string
    {
        $content = $this->controller($this->emptyReader(), lineage: $lineage)(Request::create('/_storm/view/correlations'.$query))->getContent();

        self::assertIsString($content);

        return $content;
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

    private function emptyReader(): StreamReader
    {
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveByFilter')->willReturnCallback(static function (): Generator {
            yield from [];
        });

        return $reader;
    }

    private function controller(StreamReader $reader, bool $anonymous = true, ?CorrelationLineage $lineage = null): CorrelationViewController
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new CorrelationViewController(
            $reader,
            new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
            new StoredEventResourceFactory,
            new CorrelationTraceView,
            $lineage ?? new StubLineage([]),
        );
    }
}

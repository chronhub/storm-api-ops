<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\StoredEventResource;
use Storm\ApiOps\View\CorrelationTraceView;

final class CorrelationTraceViewTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_stored_payload_cannot_carry_markup_into_the_page(): void
    {
        // the assertion this page exists to survive: it prints STORED strings, the least trustworthy
        // in the system, and the veil upstream hides personal values without making anything safe
        // for HTML. A payload is an attacker-reachable surface wherever an app stores free text.
        $html = new CorrelationTraceView()->render(
            [$this->event(payload: ['note' => '<script>alert(1)</script>'], type: '<img src=x onerror=alert(2)>')],
            ['corr-9'],
            [],
            false,
            0,
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_hostile_id_cannot_break_out_of_the_form_value(): void
    {
        // the set is echoed back into an attribute, which is a quoting context of its own
        $html = new CorrelationTraceView()->render([], ['" onfocus="alert(1)'], ['nothing found'], false, 0);

        self::assertStringNotContainsString('onfocus="alert(1)"', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    #[Test]
    public function an_empty_page_carries_the_reason_it_is_empty(): void
    {
        // the lesson this screen inherits: a blank answer that does not say why is indistinguishable
        // from a broken one, and an operator acts differently on each
        $html = new CorrelationTraceView()->render([], [], ['Name a correlation id to trace.'], false, 0);

        self::assertStringContainsString('Name a correlation id to trace.', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    #[Test]
    public function the_set_actually_queried_is_echoed_back(): void
    {
        // a composed lineage must never be invisible: the form shows what was asked, widening included
        $html = new CorrelationTraceView()->render([$this->event()], ['corr-9', 'corr-child'], [], true, 0);

        self::assertStringContainsString('value="corr-9,corr-child"', $html);
        self::assertStringContainsString('checked', $html);
    }

    #[Test]
    public function the_summary_counts_facts_and_never_calls_them_waiting(): void
    {
        // stored events HAPPENED; a screen that framed a count of facts as work in progress would
        // read as a queue, which is the reading the backlog relevé warned about
        $html = new CorrelationTraceView()->render(
            [$this->event(stream: 'account-1'), $this->event(stream: 'card-7')],
            ['corr-9'],
            [],
            false,
            0,
        );

        // scoped to the SUMMARY and not to the document: the shared navigation names the backlog
        // screen, and forbidding a word page-wide would fail on a link that is perfectly correct.
        // What must not happen is this line framing stored facts as work in progress.
        self::assertSame(1, preg_match('#<p class="sum">(.*?)</p>#', $html, $summary));
        $line = $summary[1] ?? '';
        self::assertStringContainsString('2 event(s) across 2 stream(s)', $line);
        self::assertStringNotContainsString('pending', $line);
        self::assertStringNotContainsString('waiting', $line);
        self::assertStringNotContainsString('backlog', $line);
    }

    #[Test]
    public function the_page_polls_only_when_a_refresh_was_asked_for(): void
    {
        $idle = new CorrelationTraceView()->render([$this->event()], ['corr-9'], [], false, 0);
        $polling = new CorrelationTraceView()->render([$this->event()], ['corr-9'], [], false, 5);

        self::assertStringNotContainsString('setTimeout', $idle);
        self::assertStringContainsString('location.reload()', $polling);
        self::assertStringContainsString('5000', $polling);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_payload_slash_is_printed_as_typed_not_escaped_into_noise(): void
    {
        // the json flags are load-bearing for a screen a human reads: without them a stream name or
        // a URL in a payload comes out as `a\/b`, which an operator would copy and fail to match
        $html = new CorrelationTraceView()->render(
            [$this->event(payload: ['path' => 'account/7', 'who' => 'Ann Sørensen'])],
            ['corr-9'],
            [],
            false,
            0,
        );

        self::assertStringContainsString('account/7', $html);
        self::assertStringNotContainsString('account\\/7', $html);
        self::assertStringContainsString('Sørensen', $html);
    }

    #[Test]
    public function every_notice_is_rendered_not_only_the_first(): void
    {
        $html = new CorrelationTraceView()->render([], ['corr-9'], ['first reason', 'second reason'], false, 0);

        self::assertStringContainsString('first reason', $html);
        self::assertStringContainsString('second reason', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_notice_is_wrapped_and_escaped_like_everything_else(): void
    {
        // the notice channel carries strings the SERVER composed, but one of them echoes the ids the
        // caller typed back at them; unwrapped, the notices would render as bare text with no
        // escaping at all, and the page would still look right on a benign input
        $html = new CorrelationTraceView()->render([], ['x'], ['<b>corr-9</b> matched nothing'], false, 0);

        self::assertStringContainsString('<li>', $html);
        self::assertStringNotContainsString('<b>corr-9</b>', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
    }

    #[Test]
    public function a_page_with_nothing_to_say_carries_no_empty_notice_shell(): void
    {
        // an empty `<ul>` above the table reads as a warning the operator cannot find
        $html = new CorrelationTraceView()->render([$this->event()], ['corr-9'], [], false, 0);

        self::assertStringNotContainsString('class="notice"', $html);
    }

    #[Test]
    public function the_refresh_box_shows_the_interval_in_force_and_stays_blank_when_idle(): void
    {
        // the control must read back what is actually happening: a page reloading every five seconds
        // with an empty box would leave the operator hunting for what moves the screen
        self::assertStringContainsString('name="refresh" value="5"', new CorrelationTraceView()->render([], [], [], false, 5));
        self::assertStringContainsString('name="refresh" value=""', new CorrelationTraceView()->render([], [], [], false, 0));
    }

    #[Test]
    public function the_children_box_reads_back_unchecked_when_no_lineage_was_asked(): void
    {
        self::assertStringNotContainsString('checked', new CorrelationTraceView()->render([], [], [], false, 0));
    }

    #[Test]
    public function every_event_reaches_the_table_not_only_the_first(): void
    {
        $html = new CorrelationTraceView()->render(
            [$this->event(type: 'app.first'), $this->event(type: 'app.second')],
            ['corr-9'],
            [],
            false,
            0,
        );

        self::assertStringContainsString('app.first', $html);
        self::assertStringContainsString('app.second', $html);
    }

    #[Test]
    public function two_events_on_one_stream_count_as_one_stream(): void
    {
        // the summary counts DISTINCT streams; counting rows instead would tell an operator the
        // trace spans more of the system than it does
        $html = new CorrelationTraceView()->render(
            [$this->event(stream: 'account-1'), $this->event(stream: 'account-1')],
            ['corr-9'],
            [],
            false,
            0,
        );

        self::assertStringContainsString('2 event(s) across 1 stream(s)', $html);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(array $payload = ['what' => 'something'], string $type = 'app.something_happened', string $stream = 'account-1'): StoredEventResource
    {
        return new StoredEventResource(
            position: 7,
            stream: $stream,
            type: $type,
            payload: $payload,
            headers: [],
            recordedAt: '2026-08-23T10:00:00.000000+00:00',
        );
    }
}

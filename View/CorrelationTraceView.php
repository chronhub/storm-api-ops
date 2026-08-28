<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Storm\ApiOps\Resource\StoredEventResource;

use function count;
use function implode;
use function json_encode;
use function sprintf;

/**
 * The correlation trace as one server-rendered page, in plain PHP.
 *
 * Pure by construction, string in and string out, so the shape of the page is testable without a
 * kernel, a browser, or a store. The document, the style and the escaping belong to the shared
 * chrome; what lives here is the timeline itself.
 *
 * An empty page always says WHY it is empty, never just fewer rows: no set asked, a set that
 * matched nothing, and a lineage that could not be resolved are three different answers, and an
 * operator acts differently on each.
 */
final readonly class CorrelationTraceView
{
    public function __construct(
        private ViewPage $page = new ViewPage,
    ) {}

    /**
     * @param  list<StoredEventResource>  $events  in `sequence_no` order, as the store returned them
     * @param  list<string>  $ids  the set actually queried, lineage included when it was composed
     * @param  list<string>  $notices  why the page shows what it shows, rendered above the table
     */
    public function render(array $events, array $ids, array $notices, bool $withChildren, int $refreshSeconds): string
    {
        $rows = $events === []
            ? ''
            : implode('', array_map($this->row(...), $events));

        return $this->page->render(
            'correlation trace',
            $this->form($ids, $withChildren, $refreshSeconds)
            .$this->noticeBlock($notices)
            .($events === [] ? '' : $this->table($rows, $events, $ids)),
            $refreshSeconds,
        );
    }

    private function row(StoredEventResource $event): string
    {
        $payload = json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf(
            '<tr><td class="n">%d</td><td>%s</td><td>%s</td><td class="t">%s</td><td><pre>%s</pre></td></tr>',
            $event->position,
            $this->page->text($event->recordedAt),
            $this->page->text($event->stream),
            $this->page->text($event->type),
            $this->page->text($payload === false ? '(payload not representable as json)' : $payload),
        );
    }

    /**
     * @param  list<StoredEventResource>  $events
     * @param  list<string>  $ids
     */
    private function table(string $rows, array $events, array $ids): string
    {
        $streams = [];

        foreach ($events as $event) {
            // @infection-ignore-all equivalent: an isset-style key set, the value is never read, so
            // any value under the key behaves identically; the precedent is MetricsExposition's $seen
            $streams[$event->stream] = true;
        }

        // counted, never called a backlog: these are events that HAPPENED, and a screen that framed
        // a count of stored facts as work in progress would read as a queue
        $summary = sprintf(
            '<p class="sum">%d event(s) across %d stream(s), for %d correlation id(s).</p>',
            count($events),
            count($streams),
            count($ids),
        );

        return $summary.'<table><thead><tr><th class="n">#</th><th>recorded at</th><th>stream</th><th>type</th><th>payload</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /**
     * @param  list<string>  $notices
     */
    private function noticeBlock(array $notices): string
    {
        if ($notices === []) {
            return '';
        }

        return '<ul class="notice">'
            .implode('', array_map(fn (string $notice): string => '<li>'.$this->page->text($notice).'</li>', $notices))
            .'</ul>';
    }

    /**
     * @param  list<string>  $ids
     */
    private function form(array $ids, bool $withChildren, int $refreshSeconds): string
    {
        $value = $this->page->text(implode(',', $ids));
        $checked = $withChildren ? ' checked' : '';
        // @infection-ignore-all equivalent: the cast serves the ANALYSER; an int interpolated into
        // the heredoc below renders the same characters, so dropping it changes no rendered byte
        $refresh = $refreshSeconds > 0 ? (string) $refreshSeconds : '';

        return <<<HTML
            <form method="get">
                <label>correlation ids <input name="ids" value="{$value}" size="60" placeholder="one id, or several comma-separated"></label>
                <label title="resolves the saga's children and adds their ids to the set; the set actually queried is echoed back above the table"><input type="checkbox" name="children" value="1"{$checked}> include saga children</label>
                <label>refresh every <input name="refresh" value="{$refresh}" size="3" placeholder="s"> s</label>
                <button type="submit">trace</button>
            </form>
            HTML;
    }
}

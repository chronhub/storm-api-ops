<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Resource\BacklogResource;

use function array_map;
use function count;
use function implode;
use function is_float;
use function sprintf;

/**
 * What is waiting in storm's own queues, and how long the oldest of it has waited.
 *
 * Two things this screen refuses to blur, both bought with an incident behind them. A collector that
 * failed is announced ABOVE the numbers rather than under them: a block missing because its read
 * threw looks exactly like a queue that is empty, and an operator reading the page top-down would
 * act on the wrong one. And a retained row is never called waiting; the saga command outbox holds
 * millions of `published` rows by design, and a column that summed them under a heading about
 * backlog would invent an incident every time the page is opened.
 */
final readonly class BacklogView
{
    /** Family names whose samples are ages rather than counts, rendered in seconds. */
    private const array AGE_FAMILIES = [
        'storm_outbox_events_oldest_pending_age_seconds' => true,
        'storm_saga_outbox_oldest_pending_age_seconds' => true,
    ];

    public function __construct(
        private ViewPage $page = new ViewPage,
    ) {}

    public function render(BacklogResource $backlog, int $refreshSeconds): string
    {
        $body = $this->degradedBlock($backlog->degraded);

        $body .= $backlog->families === []
            ? '<ul class="notice"><li>No backlog family answered. Storm reports its own queues only, so an installation whose outbox tables are absent has nothing to show here.</li></ul>'
            : implode('', array_map($this->family(...), $backlog->families));

        $body .= '<p class="sum">These are storm\'s OWN queues. What a broker holds is invisible here: nothing in this framework reads a transport, so a message that has left the outbox and is not yet consumed appears on no page.</p>';

        return $this->page->render('backlog', $this->form($refreshSeconds).$body, $refreshSeconds);
    }

    /**
     * @param  array{name: string, help: string, samples: list<array{labels: array<string, string>, value: int|float}>}  $family
     */
    private function family(array $family): string
    {
        $unit = isset(self::AGE_FAMILIES[$family['name']]) ? ' s' : '';

        $rows = $family['samples'] === []
            // a family that answered with nothing is not the same as a family that did not answer,
            // and the degraded block above carries the second case
            ? '<tr><td colspan="2">nothing to report</td></tr>'
            : implode('', array_map(fn (array $sample): string => sprintf(
                '<tr><td>%s</td><td class="n">%s%s</td></tr>',
                $this->page->text($this->labelsOf($sample['labels'])),
                $this->page->text(is_float($sample['value']) ? sprintf('%.3f', $sample['value']) : (string) $sample['value']),
                $unit,
            ), $family['samples']));

        return sprintf(
            '<table><thead><tr><th>%s</th><th class="n">%s</th></tr></thead><tbody>%s</tbody></table><p class="sum">%s</p>',
            $this->page->text($family['name']),
            $unit === '' ? 'count' : 'age',
            $rows,
            $this->page->text($family['help']),
        );
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labelsOf(array $labels): string
    {
        if ($labels === []) {
            return 'total';
        }

        $parts = [];

        foreach ($labels as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<string>  $degraded
     */
    private function degradedBlock(array $degraded): string
    {
        if ($degraded === []) {
            return '';
        }

        return sprintf(
            '<ul class="degraded"><li>%d collector(s) failed during this read, so their block is MISSING rather than empty: %s</li></ul>',
            count($degraded),
            $this->page->text(implode(', ', $degraded)),
        );
    }

    private function form(int $refreshSeconds): string
    {
        $refresh = $this->page->text($refreshSeconds > 0 ? (string) $refreshSeconds : '');

        return <<<HTML
            <form method="get">
                <label>refresh every <input name="refresh" value="{$refresh}" size="3" placeholder="s"> s</label>
                <button type="submit">apply</button>
            </form>
            HTML;
    }
}

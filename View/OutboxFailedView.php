<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Resource\OutboxFailedResource;

use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * The outbox dead-letter: the rows the relay gave up on, in the order a requeue would republish
 * them.
 *
 * No payload, and the console verb shows none either. What an operator triages a dead-letter on is
 * its cause and its age; the content is what the row was carrying, not why it stopped, and putting
 * it on a page would put stored personal data on a screen for a question it does not answer.
 *
 * The page is a WINDOW and says so when it is full. A dead-letter that grew unattended is exactly
 * the case an operator opens this for, and a table that silently showed its first fifty rows while
 * looking complete would be worse than one that showed none.
 */
final readonly class OutboxFailedView
{
    public function __construct(
        private ViewPage $page = new ViewPage,
    ) {}

    /**
     * @param  list<OutboxFailedResource>  $rows  one page, in id order
     * @param  int  $limit  the window actually applied, after the server-side clamp
     */
    public function render(array $rows, int $after, int $limit, int $refreshSeconds): string
    {
        $body = $this->form($after, $limit, $refreshSeconds);

        $body .= $rows === []
            ? sprintf(
                '<ul class="notice"><li>%s</li></ul>',
                $after > 0
                    ? 'No dead-lettered row past this cursor. The window is exhausted, which is the end of the list and not an empty one.'
                    : 'The outbox dead-letter is empty: no event has been given up on. A row lands here only after its delivery attempts are spent.',
            )
            : $this->table($rows, $limit);

        return $this->page->render('dead-letters', $body, $refreshSeconds);
    }

    /**
     * @param  list<OutboxFailedResource>  $rows
     */
    private function table(array $rows, int $limit): string
    {
        $cells = implode('', array_map($this->row(...), $rows));

        $summary = count($rows) < $limit
            ? sprintf('<p class="sum">%d row(s); the window was not filled, so this is the whole dead-letter from here on.</p>', count($rows))
            // the cursor is the LAST id of the page, which is what makes the next window strictly
            // after it; a page that filled its window says nothing about what lies past it
            : sprintf(
                '<p class="sum">%d row(s), the window is FULL: there may be more past this page. <a href="?after=%d&amp;limit=%d">next</a></p>',
                count($rows),
                $rows[count($rows) - 1]->id,
                $limit,
            );

        return $summary
            .'<table><thead><tr><th class="n">id</th><th class="n">position</th><th>type</th><th class="n">attempts</th><th>failed at</th><th>error</th></tr></thead><tbody>'
            .$cells
            .'</tbody></table>';
    }

    private function row(OutboxFailedResource $row): string
    {
        return sprintf(
            '<tr><td class="n">%d</td><td class="n">%d</td><td class="t">%s</td><td class="n">%d</td><td class="t">%s</td><td><pre>%s</pre></td></tr>',
            $row->id,
            $row->position,
            $this->page->text($row->type),
            $row->attempts,
            // a row given up on without a recorded moment keeps its blank rather than borrowing one
            $this->page->text($row->failedAt ?? '—'),
            $this->page->text($row->lastError ?? '—'),
        );
    }

    private function form(int $after, int $limit, int $refreshSeconds): string
    {
        $afterValue = $this->page->text($after > 0 ? (string) $after : '');
        $limitValue = $this->page->text((string) $limit);
        $refresh = $this->page->text($refreshSeconds > 0 ? (string) $refreshSeconds : '');

        return <<<HTML
            <form method="get">
                <label>after row id <input name="after" value="{$afterValue}" size="8" placeholder="from the start"></label>
                <label>page size <input name="limit" value="{$limitValue}" size="4"></label>
                <label>refresh every <input name="refresh" value="{$refresh}" size="3" placeholder="s"> s</label>
                <button type="submit">apply</button>
            </form>
            HTML;
    }
}

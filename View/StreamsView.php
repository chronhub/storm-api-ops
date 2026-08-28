<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Resource\StreamResource;

use function array_filter;
use function array_map;
use function count;
use function http_build_query;
use function implode;
use function sprintf;

/**
 * The store's stream directory: which streams exist and how far each has been written.
 *
 * A directory and nothing more. No row opens its events, and the absence is the decision the
 * surface made rather than a gap: the events of a stream are a payload-bearing read, the question
 * an operator actually brings is a correlation, and the trace screen already answers that one
 * across streams. A second payload surface would exist for no named need.
 *
 * The page is a WINDOW over a population as large as the store's, one row per stream and one
 * aggregate being one stream. It says when the window is full and hands the cursor forward, and
 * that cursor carries the CATEGORY with it: a next page that dropped the narrowing would answer a
 * wider question than the one on screen, without a word.
 */
final readonly class StreamsView
{
    public function __construct(
        private ViewPage $page = new ViewPage,
    ) {}

    /**
     * @param  list<StreamResource>  $streams  one window, in stream-name order
     * @param  string  $after  the cursor that was applied, empty from the start of the directory
     * @param  int  $limit  the window actually applied, after the server-side clamp
     */
    public function render(array $streams, string $category, string $after, int $limit, int $refreshSeconds): string
    {
        $body = $this->form($category, $after, $limit, $refreshSeconds);

        $body .= $streams === []
            ? sprintf('<ul class="notice"><li>%s</li></ul>', $this->page->text($this->emptiness($category, $after)))
            : $this->table($streams, $category, $limit, $refreshSeconds);

        return $this->page->render('streams', $body, $refreshSeconds);
    }

    /**
     * Which emptiness is speaking. An operator acts differently on each, and one "no streams" would
     * hide the two that are not about the store at all.
     */
    private function emptiness(string $category, string $after): string
    {
        if ($after !== '') {
            return 'No stream past this cursor. The window is exhausted, which is the end of the directory and not an empty one.';
        }

        if ($category !== '') {
            return sprintf('No stream in the "%s" lane. The category is matched exactly, so a lane nobody has written to looks the same as one whose name was mistyped.', $category);
        }

        return 'The store holds no stream. Nothing has been committed here yet; this reads the stream heads, so a store with events and no heads would be a defect rather than an empty page.';
    }

    /**
     * @param  list<StreamResource>  $streams
     */
    private function table(array $streams, string $category, int $limit, int $refreshSeconds): string
    {
        $summary = count($streams) < $limit
            ? sprintf('<p class="sum">%d stream(s); the window was not filled, so this is the whole directory from here on.</p>', count($streams))
            // the cursor is the LAST name of the page, which is what makes the next window strictly
            // after it; a page that filled its window says nothing about what lies past it
            : sprintf(
                '<p class="sum">%d stream(s), the window is FULL: there may be more past this page. <a href="%s">next</a></p>',
                count($streams),
                $this->page->text($this->cursor($category, $streams[count($streams) - 1]->stream, $limit, $refreshSeconds)),
            );

        return $summary
            .'<table><thead><tr><th>stream</th><th class="n">last version</th></tr></thead><tbody>'
            .implode('', array_map($this->row(...), $streams))
            .'</tbody></table>';
    }

    /**
     * The next window, carrying every narrowing the current one applied.
     */
    private function cursor(string $category, string $after, int $limit, int $refreshSeconds): string
    {
        $query = array_filter([
            'category' => $category,
            'after' => $after,
            'limit' => (string) $limit,
            'refresh' => $refreshSeconds > 0 ? (string) $refreshSeconds : '',
        ], static fn (string $value): bool => $value !== '');

        return '?'.http_build_query($query);
    }

    private function row(StreamResource $stream): string
    {
        return sprintf(
            '<tr><td class="t">%s</td><td class="n">%d</td></tr>',
            $this->page->text($stream->stream),
            $stream->lastVersion,
        );
    }

    private function form(string $category, string $after, int $limit, int $refreshSeconds): string
    {
        $categoryValue = $this->page->text($category);
        $afterValue = $this->page->text($after);
        $limitValue = $this->page->text((string) $limit);
        $refresh = $this->page->text($refreshSeconds > 0 ? (string) $refreshSeconds : '');

        return <<<HTML
            <form method="get">
                <label>category <input name="category" value="{$categoryValue}" size="20" placeholder="every lane"></label>
                <label>after stream <input name="after" value="{$afterValue}" size="24" placeholder="from the start"></label>
                <label>page size <input name="limit" value="{$limitValue}" size="4"></label>
                <label>refresh every <input name="refresh" value="{$refresh}" size="3" placeholder="s"> s</label>
                <button type="submit">apply</button>
            </form>
            HTML;
    }
}

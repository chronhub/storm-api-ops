<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Resource\SagaListingPageResource;
use Storm\ApiOps\Resource\SagaListingResource;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * The saga directory: which instances are worth opening, and a way into each.
 *
 * A REFUSED filter comes back on the page, one line per rejected field above the form, and the form
 * keeps every value the operator typed. The JSON twin answers a hard 422 to a script, which is what
 * a script needs; a screen that died on a mistyped box would take away the page its reader came for,
 * and one that silently corrected the value would answer a question nobody asked.
 *
 * Every row links its own detail, so the directory and the instance are one gesture apart. The
 * screen shows and never acts: the guarded verbs stay on their console and HTTP twins.
 */
final readonly class SagaListingView
{
    public function __construct(
        private ViewPage $page = new ViewPage,
        private ?UrlGeneratorInterface $urls = null,
    ) {}

    /**
     * @param  array<string, string>  $filters  the values the operator typed, echoed back whole
     * @param  list<string>  $refusals  a named refusal per rejected field, rendered above the form
     */
    public function render(?SagaListingPageResource $page, array $filters, array $refusals, int $refreshSeconds): string
    {
        $body = $this->refusalBlock($refusals).$this->form($filters, $refreshSeconds);

        if ($page === null) {
            // a refused filter leaves NO listing rather than an empty one: showing zero rows beside
            // an error would read as "your filter matched nothing", which is a different answer
            return $this->page->render('sagas', $body, $refreshSeconds);
        }

        $body .= $page->sagas === []
            ? '<ul class="notice"><li>No saga matches these filters. The directory lists what the store holds now; a workflow that finished and was cleaned up is gone from it, which is not the same as one that never ran.</li></ul>'
            : $this->table($page);

        return $this->page->render('sagas', $body, $refreshSeconds);
    }

    private function table(SagaListingPageResource $page): string
    {
        $summary = $page->truncated
            // the window is the operator's blind spot when it fills: a directory that showed its
            // first rows while looking complete would hide the very instance being hunted
            ? sprintf('<p class="sum">%d saga(s), the window of %d is FULL: narrow the filters or raise the page size to see the rest.</p>', count($page->sagas), $page->limit)
            : sprintf('<p class="sum">%d saga(s); the window of %d was not filled, so this is all of them.</p>', count($page->sagas), $page->limit);

        return $summary
            .'<table><thead><tr><th>type</th><th>correlation</th><th>step</th><th>status</th><th>updated</th><th class="n">def</th><th class="n">gen</th><th class="n">retries</th><th>flags</th></tr></thead><tbody>'
            .implode('', array_map($this->row(...), $page->sagas))
            .'</tbody></table>';
    }

    private function row(SagaListingResource $saga): string
    {
        return sprintf(
            '<tr><td class="t">%s</td><td class="t">%s</td><td class="t">%s</td><td class="t">%s</td><td class="t">%s</td><td class="n">%d</td><td class="n">%d</td><td class="n">%d</td><td class="t">%s</td></tr>',
            $this->page->text($saga->workflowType),
            $this->detailLink($saga->correlationId),
            $this->page->text($saga->stateKey),
            $this->page->text($saga->status),
            $this->page->text($saga->updatedAt ?? '—'),
            $saga->definitionVersion,
            $saga->generation,
            $saga->retryTotal,
            $this->page->text($this->flags($saga)),
        );
    }

    private function detailLink(string $correlation): string
    {
        $safe = $this->page->text($correlation);

        if ($this->urls === null) {
            return $safe;
        }

        try {
            return sprintf('<a href="%s">%s</a>', $this->page->text($this->urls->generate('storm_view_saga').'?correlation='.rawurlencode($correlation)), $safe);
        } catch (Throwable) {
            // a detail screen the application did not import costs a link, never the row
            return $safe;
        }
    }

    private function flags(SagaListingResource $saga): string
    {
        $flags = [];

        if ($saga->pausedAt !== null) {
            $flags[] = 'paused';
        }

        if ($saga->typePaused) {
            // a type-wide freeze is not the same as this instance being held, and an operator
            // lifting the wrong one would watch nothing move
            $flags[] = 'type paused';
        }

        if ($saga->waivedAt !== null) {
            $flags[] = 'waived';
        }

        if ($saga->parentCorrelationId !== null) {
            $flags[] = 'child';
        }

        return $flags === [] ? '—' : implode(', ', $flags);
    }

    /**
     * @param  list<string>  $refusals
     */
    private function refusalBlock(array $refusals): string
    {
        if ($refusals === []) {
            return '';
        }

        return '<ul class="degraded">'
            .implode('', array_map(fn (string $refusal): string => '<li>'.$this->page->text($refusal).'</li>', $refusals))
            .'</ul>';
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function form(array $filters, int $refreshSeconds): string
    {
        $value = fn (string $name): string => $this->page->text($filters[$name] ?? '');
        $refresh = $this->page->text($refreshSeconds > 0 ? (string) $refreshSeconds : '');
        $waived = ($filters['waived'] ?? '') === '1' ? ' checked' : '';

        return <<<HTML
            <form method="get">
                <label>type <input name="type" value="{$value('type')}" size="20" placeholder="any"></label>
                <label>status <input name="status" value="{$value('status')}" size="12" placeholder="any"></label>
                <label>idle for <input name="idle_for" value="{$value('idle_for')}" size="6" placeholder="s"> s</label>
                <label><input type="checkbox" name="waived" value="1"{$waived}> waived only</label>
                <label>page size <input name="limit" value="{$value('limit')}" size="4" placeholder="50"></label>
                <label>refresh every <input name="refresh" value="{$refresh}" size="3" placeholder="s"> s</label>
                <button type="submit">list</button>
            </form>
            HTML;
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Resource\SagaHistoryResource;
use Storm\ApiOps\Resource\SagaResource;

use function array_map;
use function count;
use function implode;
use function is_scalar;
use function sprintf;

/**
 * One correlation, as an operator meets it: what the saga IS doing, what it was DECLARED able to do,
 * and what it announced along the way.
 *
 * The three are juxtaposed and never merged. The instance answers "where is it now", the declaration
 * answers "what was possible", and their difference is the arc that was promised and never taken,
 * which is the question in front of a saga that stopped moving. Observed-traffic consoles cannot ask
 * it: they draw the edges they have seen.
 *
 * The screen shows and never acts. Cancel, redrive, pause and resume live on guarded twins, and a
 * detail page that offered the button would be the exact line the surface refuses to cross.
 */
final readonly class SagaDetailView
{
    public function __construct(
        private ViewPage $page = new ViewPage,
    ) {}

    /**
     * @param  list<SagaResource>  $sagas  every saga sharing this correlation, any type
     * @param  list<string>  $notices  why the page shows what it shows, rendered above everything
     */
    public function render(
        string $correlation,
        array $sagas,
        SagaDeclaration $declaration,
        ?SagaHistoryResource $history,
        array $notices,
        int $refreshSeconds,
    ): string {
        $body = $this->form($correlation, $refreshSeconds).$this->noticeBlock($notices, 'notice');

        if ($correlation === '') {
            $body .= '<ul class="notice"><li>Name a correlation to inspect. A saga carries the correlation it was started with, and its children carry their own.</li></ul>';

            return $this->page->render('saga detail', $body, $refreshSeconds);
        }

        if ($sagas === []) {
            $body .= '<ul class="notice"><li>No saga carries this correlation. It is traced exactly as given; a correlation that never started a workflow has no instance, which is not the same as one that finished.</li></ul>';

            return $this->page->render('saga detail', $body, $refreshSeconds);
        }

        foreach ($sagas as $saga) {
            $body .= $this->saga($saga, $declaration);
        }

        return $this->page->render('saga detail', $body.$this->history($history), $refreshSeconds);
    }

    private function saga(SagaResource $saga, SagaDeclaration $declaration): string
    {
        return sprintf('<h2 class="sum">%s — step %s (%s)</h2>', $this->page->text($saga->workflowType), $this->page->text($saga->stateKey), $this->page->text($saga->status))
            .$this->declared($saga, $declaration)
            .$this->rows('timers', SagaResource::TIMER_KEYS, $saga->timers)
            .$this->rows('compensations', SagaResource::COMPENSATION_KEYS, $saga->compensations)
            .$this->rows('outbox', SagaResource::OUTBOX_KEYS, $saga->outbox)
            .$this->rows('children', SagaResource::CHILD_KEYS, $saga->children);
    }

    private function declared(SagaResource $saga, SagaDeclaration $declaration): string
    {
        if (! $declaration->available) {
            // a declaration that cannot be read degrades to a named absence: the instance half of the
            // page is still worth having, and a silent omission would read as "nothing was declared"
            return sprintf('<ul class="notice"><li>Declaration unavailable: %s</li></ul>', $this->page->text($declaration->reason ?? ''));
        }

        $never = $declaration->neverTaken($saga->children);

        if ($never === []) {
            return sprintf('<p class="sum">%d spawn(s) declared, every one of them taken.</p>', count($declaration->spawns));
        }

        $lines = array_map(
            fn (array $spawn): string => sprintf(
                'slot %s spawns %s%s',
                $this->page->text($spawn['slot']),
                $this->page->text($spawn['workflow']),
                $spawn['awaited_by'] === null ? '' : ', awaited by '.$this->page->text($spawn['awaited_by']),
            ),
            $never,
        );

        return sprintf(
            '<ul class="degraded"><li>%d declared spawn(s) NEVER taken by this instance: %s</li>'
            .'<li>The match is on the child workflow type, so two spawns of one workflow cannot be told apart from the children alone.</li></ul>',
            count($never),
            implode(' — ', $lines),
        );
    }

    /**
     * @param  list<string>  $keys
     * @param  list<array<string, mixed>>  $rows
     */
    private function rows(string $title, array $keys, array $rows): string
    {
        if ($rows === []) {
            return sprintf('<p class="sum">%s: none.</p>', $this->page->text($title));
        }

        $head = implode('', array_map(fn (string $key): string => '<th>'.$this->page->text($key).'</th>', $keys));

        $body = implode('', array_map(
            fn (array $row): string => '<tr>'.implode('', array_map(
                fn (string $key): string => '<td><pre>'.$this->page->text($this->cell($row[$key] ?? null)).'</pre></td>',
                $keys,
            )).'</tr>',
            $rows,
        ));

        return sprintf('<p class="sum">%s</p><table><thead><tr>%s</tr></thead><tbody>%s</tbody></table>', $this->page->text($title), $head, $body);
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            $value === true => 'yes',
            $value === false => 'no',
            is_scalar($value) => (string) $value,
            default => '(not a scalar)',
        };
    }

    private function history(?SagaHistoryResource $history): string
    {
        if ($history === null) {
            return '';
        }

        if ($history->records === []) {
            // WHICH silence is the answer: a table that was never installed, one installed and never
            // written to, and a saga that simply announced nothing are three different situations
            return sprintf(
                '<ul class="notice"><li>No history for this correlation. The recorded reason is <strong>%s</strong>: a table that is absent, one that is present and empty, and a saga that announced nothing are three different answers.</li></ul>',
                $this->page->text($history->availability),
            );
        }

        return $this->rows('history', ['occurredAt', 'workflowType', 'generation', 'eventType'], array_map(
            static fn (object $record): array => [
                'occurredAt' => $record->occurredAt,
                'workflowType' => $record->workflowType,
                'generation' => $record->generation,
                'eventType' => $record->eventType,
            ],
            $history->records,
        )).($history->truncated ? sprintf('<p class="sum">The history window of %d was FULL: older records exist past it.</p>', $history->limit) : '');
    }

    /**
     * @param  list<string>  $notices
     */
    private function noticeBlock(array $notices, string $class): string
    {
        if ($notices === []) {
            return '';
        }

        return sprintf(
            '<ul class="%s">%s</ul>',
            $class,
            implode('', array_map(fn (string $notice): string => '<li>'.$this->page->text($notice).'</li>', $notices)),
        );
    }

    private function form(string $correlation, int $refreshSeconds): string
    {
        $value = $this->page->text($correlation);
        $refresh = $this->page->text($refreshSeconds > 0 ? (string) $refreshSeconds : '');

        return <<<HTML
            <form method="get">
                <label>correlation <input name="correlation" value="{$value}" size="48" placeholder="the correlation a saga was started with"></label>
                <label>refresh every <input name="refresh" value="{$refresh}" size="3" placeholder="s"> s</label>
                <button type="submit">inspect</button>
            </form>
            HTML;
    }
}

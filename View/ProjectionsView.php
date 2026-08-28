<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Resource\ProjectionResource;

use function array_filter;
use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * Every registered projection with the three facts an operator opens this for: how far behind it is,
 * why it stopped if it did, and whether anything is actually running it.
 *
 * The lease verdict keeps its three states apart, and the middle one is the reason this screen is
 * worth a page. An unleased projection has no liveness to report and says so; a lease that HELD when
 * the clock was asked says live; one that expired says expired, which is a worker that died rather
 * than a projection that is idle. Folding the first into the third would send an operator hunting a
 * worker that never claimed the projection.
 *
 * The stop reason stays in THREE fields, moment, class and message, because they answer three
 * questions: when it gave up, what kind of failure it was, and what the failure said. A single
 * collapsed line reads well and cannot be scanned for a repeated class across projections.
 */
final readonly class ProjectionsView
{
    public function __construct(
        private ViewPage $page = new ViewPage,
    ) {}

    /**
     * @param  list<ProjectionResource>  $projections
     */
    public function render(array $projections, int $refreshSeconds): string
    {
        if ($projections === []) {
            return $this->page->render(
                'projections',
                '<ul class="notice"><li>No projection is registered here. This lists what the application declared, so an empty page means nothing was declared, not that nothing is running.</li></ul>',
                $refreshSeconds,
            );
        }

        return $this->page->render(
            'projections',
            $this->attention($projections)
            .'<table><thead><tr><th>name</th><th>status</th><th class="n">position</th><th class="n">lag</th><th>at head</th><th>lease</th><th>stopped</th></tr></thead><tbody>'
            .implode('', array_map($this->row(...), $projections))
            .'</tbody></table>',
            $refreshSeconds,
        );
    }

    /**
     * @param  list<ProjectionResource>  $projections
     */
    private function attention(array $projections): string
    {
        $failed = array_filter($projections, static fn (ProjectionResource $p): bool => $p->failedAt !== null);
        $orphaned = array_filter($projections, static fn (ProjectionResource $p): bool => $p->leaseLive === false);

        if ($failed === [] && $orphaned === []) {
            return '';
        }

        $lines = [];

        if ($failed !== []) {
            $lines[] = sprintf('%d projection(s) stopped on a failure; the reason is on their row.', count($failed));
        }

        if ($orphaned !== []) {
            // an expired lease is a worker that DIED, which is not the same as a projection nobody
            // started: the second has no lease at all and is not counted here
            $lines[] = sprintf('%d projection(s) hold a lease that has EXPIRED: their worker stopped without releasing it.', count($orphaned));
        }

        return '<ul class="degraded"><li>'.implode('</li><li>', array_map($this->page->text(...), $lines)).'</li></ul>';
    }

    private function row(ProjectionResource $projection): string
    {
        return sprintf(
            '<tr><td class="t">%s</td><td class="t">%s</td><td class="n">%d</td><td class="n">%d</td><td>%s</td><td class="t">%s</td><td>%s</td></tr>',
            $this->page->text($projection->name),
            $this->page->text($projection->status),
            $projection->position,
            $projection->lag,
            $projection->atHead ? 'yes' : 'no',
            $this->page->text($this->lease($projection)),
            $this->stopped($projection),
        );
    }

    private function lease(ProjectionResource $projection): string
    {
        if ($projection->leaseOwner === null) {
            // no lease is not an expired one: nothing has claimed this projection
            return 'unclaimed';
        }

        return sprintf(
            '%s (%s%s)',
            $projection->leaseOwner,
            $projection->leaseLive === true ? 'live' : 'expired',
            // the horizon and not just the verdict: `expired` alone leaves the operator wondering
            // whether the worker died a second ago or an hour ago
            $projection->leaseUntil === null ? '' : ' until '.$projection->leaseUntil,
        );
    }

    private function stopped(ProjectionResource $projection): string
    {
        if ($projection->failedAt === null) {
            return '—';
        }

        return sprintf(
            "<pre>%s\n%s\n%s</pre>",
            $this->page->text($projection->failedAt),
            $this->page->text($projection->errorClass ?? '(no class recorded)'),
            $this->page->text($projection->errorMessage ?? '(no message recorded)'),
        );
    }
}

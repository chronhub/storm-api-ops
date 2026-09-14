<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\CorrelationIdSet;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StoredEventResourceFactory;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Store\StreamReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Throwable;

use function count;
use function in_array;
use function max;
use function min;
use function sprintf;

/**
 * The correlation trace screen: reads what T1 already serves and renders it, nothing more.
 *
 * It composes a lineage only when the operator ASKS for it, and echoes the set it actually queried
 * back into the form. That is the whole shape of the rule the store side holds: a lineage is a set
 * resolved where it is known, never a preset meaning "the whole tree" that would say two different
 * things in two applications. Here the composing is a checkbox and its result is visible; nothing
 * widens a query behind the operator's back.
 *
 * It asks its lineage seam for one thing, the child ids, so the coordination module's snapshot shape
 * never reaches a template. A resolution that fails degrades to the typed set with the reason on the
 * page, rather than to a 500: the operator still gets the trace it came for and learns that the
 * lineage half is what broke.
 *
 * Tagged `AsController` rather than left to autoconfiguration, and it is the first of its kind here:
 * the bundle's own ops controllers are picked up from a class-level `#[Route]`, which this one has
 * no business carrying. Its route is declared by resource metadata like every other route of this
 * surface, and a `#[Route]` added only to earn a tag would announce a second registration that does
 * not exist.
 */
#[AsController]
final readonly class CorrelationViewController
{
    /**
     * The lineage walk stops here; a spike screen has no business paging a tree. It is the walk's
     * own stop and not the predicate's: the composed set also stops at the width every correlation
     * surface shares, whichever of the two comes first, so widening by checkbox can never reach a
     * set a typed one would be refused for.
     */
    public const int MAX_CHILDREN = 50;

    public function __construct(
        private StreamReader $reader,
        private OpsActorGate $gate,
        private StoredEventResourceFactory $resources,
        private CorrelationTraceView $view,
        private CorrelationLineage $lineage,
        private OpsAuditLog $audit,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    public function __invoke(Request $request): Response
    {
        // @infection-ignore-all equivalent: the cast serves the ANALYSER, the query bag being typed
        // mixed; the default is already a string and the router hands nothing else here
        $raw = (string) $request->query->get('ids', '');

        // the gate answers before the parse, for the same reason it does on the JSON window: an
        // unnamed caller learns nothing, not even whether its input was usable
        $this->gate->assertOwnedIdentityForRead('correlations.read', $raw);

        $withChildren = $request->query->get('children') === '1';
        $refresh = $this->refreshSeconds($request);
        $ids = CorrelationIdSet::parse($raw);
        $notices = [];

        if ($ids === []) {
            return $this->html($this->view->render([], [], ['Name a correlation id to trace. Several, comma-separated, are traced together.'], $withChildren, $refresh));
        }

        if (CorrelationIdSet::isTooWide($ids)) {
            // narrowed and reported, not refused: cutting the set can only match fewer rows, and an
            // operator who pasted a batch still gets a trace plus the reason it is not the whole one
            $notices[] = sprintf(
                'Only the first %d of the %d ids named are traced; a wider set stops being a trace. Ask again with the rest.',
                CorrelationIdSet::MAX_IDS,
                count($ids),
            );
            $ids = CorrelationIdSet::narrow($ids);
        }

        if ($withChildren) {
            [$ids, $notice] = $this->withLineage($ids);

            if ($notice !== null) {
                $notices[] = $notice;
            }
        }

        $events = [];
        foreach ($this->reader->retrieveByFilter(new CorrelationFeedFilter($ids, PageWindow::MAX_LIMIT)) as $record) {
            $events[] = $this->resources->fromRecord($record);
        }

        if ($events === []) {
            $notices[] = sprintf('No stored event carries %s. The ids are traced exactly as given; an id that never rode a message has no footprint.', implode(', ', $ids));
        }

        if (count($events) >= PageWindow::MAX_LIMIT) {
            $notices[] = sprintf('The trace reached the %d-event limit and may be incomplete.', PageWindow::MAX_LIMIT);
        }

        $response = $this->html($this->view->render($events, $ids, $notices, $withChildren, $refresh));
        $this->audit->record('correlations.read', implode(',', $ids), sprintf('served %d event(s)', count($events)));

        return $response;
    }

    /**
     * @param  non-empty-list<string>  $ids
     * @return array{0: non-empty-list<string>, 1: string|null}
     */
    private function withLineage(array $ids): array
    {
        // whichever ceiling comes first, so a composed set can never be wider than a typed one is
        // admitted at, however either bound moves later
        $ceiling = min(self::MAX_CHILDREN, CorrelationIdSet::MAX_IDS);
        $composed = $ids;
        $leftOut = false;

        try {
            foreach ($ids as $id) {
                foreach ($this->lineage->childrenOf($id) as $child) {
                    if (in_array($child, $composed, true)) {
                        continue;
                    }

                    if (count($composed) >= $ceiling) {
                        $leftOut = true;

                        // @infection-ignore-all; equivalent: the full set cannot shrink and later array entries cannot change the result
                        continue;
                    }

                    $composed[] = $child;
                }
            }
        } catch (Throwable $e) {
            // a spike screen degrades to the set it was given rather than to a 500: the operator
            // still gets the trace, and learns that the lineage half is what failed
            return [$ids, sprintf('The lineage could not be resolved (%s); the set below is the one you typed.', $e::class)];
        }

        if ($leftOut) {
            // said before the two answers below, because a child that exists and did not fit is
            // neither an absent lineage nor a complete one, and the operator acts differently on it.
            // The size QUERIED is named and not the ceiling: a typed set already past the walk's own
            // stop leaves the two numbers apart, and a notice naming one the set below contradicts
            // costs the operator more than saying nothing would
            return [$composed, sprintf('Lineage stopped at %d id(s); some children were left out of the set below.', count($composed))];
        }

        if ($composed === $ids) {
            return [$ids, 'No child correlation was found, so the set below is the one you typed.'];
        }

        return [$composed, sprintf('Lineage composed: %d id(s) queried, the typed one(s) plus their saga children.', count($composed))];
    }

    private function refreshSeconds(Request $request): int
    {
        $raw = $request->query->get('refresh');

        // clamped, never refused: a refresh box is a comfort control on a read-only page, and a
        // typo there must not cost the operator the trace it came for
        // @infection-ignore-all equivalent: the floor's value below 1 is unobservable, the page
        // polling on `> 0`, so zero and a negative are the same answer to a reader
        return is_numeric($raw) ? max(0, min(300, (int) $raw)) : 0;
    }

    private function html(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

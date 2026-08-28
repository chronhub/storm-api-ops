<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Exception;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\State\SagaListingProvider;
use Storm\Saga\Store\WorkflowStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

use function array_column;
use function array_diff;
use function array_keys;
use function implode;
use function is_string;
use function sprintf;
use function trim;

/**
 * The saga directory screen, rendering what `/_storm/sagas` serves as JSON, entered through the
 * filters an operator types rather than the query string a script builds.
 *
 * A refused filter comes back ON the page and costs the listing: rows shown beside an error would
 * read as "your filter matched nothing", which answers a question nobody asked. The JSON twin keeps
 * its hard 422, which is what a script needs.
 *
 * The gate answers BEFORE the filters are judged, the idiom the correlation screen holds: an unnamed
 * caller learns nothing here, not even whether its input was usable.
 *
 * `status` is judged on this side and not left to the provider. The enum constraint that turns a
 * typo into a 422 lives in the JSON resource's parameter metadata, which this route does not carry;
 * the provider alone reads an unknown status as NO filter, so the operator would scan a whole
 * directory believing it narrowed. The two integer filters are the opposite case: the provider is
 * their authority on both channels, and its named refusal is caught and rendered.
 *
 * A query key this screen does not know is refused by name for the same reason, and it is the only
 * screen of the surface that owes it: `sagas` is the directory and `saga` is the instance, so a
 * link or a bookmark aimed at one lands on the other with a parameter it never reads. Ignored, that
 * arrival would render a full directory and look like an answer.
 */
#[AsController]
final readonly class SagaListingViewController
{
    /** The filter boxes, in the order the form carries them; the only query keys read as filters. */
    private const array FIELDS = ['type', 'status', 'idle_for', 'waived', 'limit'];

    public function __construct(
        private OpsActorGate $gate,
        private SagaListingProvider $sagas,
        private SagaListingView $view,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws Exception on a raw DBAL read failure
     */
    public function __invoke(Request $request): Response
    {
        $this->gate->assertOwnedIdentityForRead('sagas.read', '*');

        $refresh = ViewRefresh::secondsFrom($request);
        $filters = $this->typedFilters($request);
        $refusals = $this->refusals($request, $filters);

        if ($refusals !== []) {
            return $this->html($this->view->render(null, $filters, $refusals, $refresh));
        }

        try {
            $page = $this->sagas->provide(new GetCollection, [], ['filters' => $filters]);
        } catch (MalformedQueryParameter $e) {
            return $this->html($this->view->render(null, $filters, [$e->getMessage()], $refresh));
        }

        return $this->html($this->view->render($page, $filters, [], $refresh));
    }

    /**
     * What the operator actually typed, empty boxes dropped.
     *
     * An empty box is ABSENT and never an empty value: passed on, `limit=` would be refused as a
     * malformed window and the operator would lose the page for having submitted the form untouched.
     *
     * @return array<string, string>
     */
    private function typedFilters(Request $request): array
    {
        $typed = [];

        foreach (self::FIELDS as $field) {
            $raw = $request->query->get($field);

            if (is_string($raw) && trim($raw) !== '') {
                $typed[$field] = trim($raw);
            }
        }

        return $typed;
    }

    /**
     * Everything wrong with the query, named field by field, so one submission does not cost two
     * round trips to learn two mistakes.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function refusals(Request $request, array $filters): array
    {
        $refusals = [];

        foreach (array_diff(array_keys($request->query->all()), self::FIELDS) as $unknown) {
            if ($unknown !== ViewRefresh::KEY) {
                $refusals[] = sprintf(
                    'The "%s" query parameter means nothing to this screen. The directory filters on %s; one saga at a time is the other screen.',
                    $unknown,
                    implode(', ', self::FIELDS),
                );
            }
        }

        $status = $filters['status'] ?? null;

        if ($status !== null && WorkflowStatus::tryFrom($status) === null) {
            $refusals[] = sprintf(
                'The "status" filter must name a lifecycle status; got "%s". The known ones are %s. Dropping it is what widens the listing; a value that cannot be read never does.',
                $status,
                implode(', ', array_column(WorkflowStatus::cases(), 'value')),
            );
        }

        return $refusals;
    }

    private function html(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\SagaHistoryResource;
use Storm\ApiOps\State\DescribeProvider;
use Storm\ApiOps\State\SagaHistoryProvider;
use Storm\ApiOps\State\SagasProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Throwable;

use function sprintf;
use function trim;

/**
 * The saga detail screen: three reads juxtaposed, the instance, its declaration and its history.
 *
 * The history and the declaration are BEST-EFFORT halves: either can be unavailable on an
 * installation that did not wire it, and neither absence is allowed to cost the instance half.
 */
#[AsController]
final readonly class SagaDetailViewController
{
    public function __construct(
        private OpsActorGate $gate,
        private SagasProvider $sagas,
        private DescribeProvider $describe,
        private SagaHistoryProvider $history,
        private SagaDetailView $view,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    public function __invoke(Request $request): Response
    {
        $correlation = trim((string) $request->query->get('correlation', ''));
        $refresh = ViewRefresh::secondsFrom($request);

        // the gate answers FIRST and here rather than through a provider, the way the correlation
        // screen does: an empty form is not a read, and reaching the store just to make a provider
        // run the gate would charge a query for a page that shows an input box
        $this->gate->assertOwnedIdentityForRead('saga.read', $correlation === '' ? '*' : $correlation);

        if ($correlation === '') {
            return $this->html($this->view->render('', [], SagaDeclaration::forType(null, ''), null, [], $refresh));
        }

        $notices = [];

        // no named-refusal catch here, and its absence is checked rather than assumed: this provider
        // declares no query parameter to misread, so a catch would be a guard that cannot fire. The
        // listing screen is where that catch belongs, its provider parsing a window and a filter.
        $sagas = $this->sagas->provide(new GetCollection, ['correlationId' => $correlation]);

        $type = $sagas === [] ? '' : $sagas[0]->workflowType;

        [$declaration, $declarationNotice] = $this->declaration($type);
        [$history, $historyNotice] = $this->historyOf($correlation);

        if ($declarationNotice !== null) {
            $notices[] = $declarationNotice;
        }

        if ($historyNotice !== null) {
            $notices[] = $historyNotice;
        }

        return $this->html($this->view->render($correlation, $sagas, $declaration, $history, $notices, $refresh));
    }

    /**
     * @return array{0: SagaDeclaration, 1: string|null}
     */
    private function declaration(string $type): array
    {
        try {
            // @infection-ignore-all equivalent: the section NARROWS the work, not the answer — the
            // whole document carries `workflows` just the same, so dropping the filter costs the
            // other seven sections and changes no value this screen reads
            $document = $this->describe->provide(new Get, [], ['filters' => ['section' => 'workflows']]);

            return [SagaDeclaration::forType($document->workflows, $type), null];
        } catch (Throwable $e) {
            // the instance half is worth having on its own; a describe that threw must not take the
            // page with it, and the operator is told which half went missing
            return [SagaDeclaration::forType(null, $type), sprintf('The declaration could not be read (%s); the instance below is unaffected.', $e::class)];
        }
    }

    /**
     * @return array{0: SagaHistoryResource|null, 1: string|null}
     */
    private function historyOf(string $correlation): array
    {
        try {
            return [$this->history->provide(new GetCollection, ['correlationId' => $correlation]), null];
        } catch (Throwable $e) {
            return [null, sprintf('The history could not be read (%s); the instance below is unaffected.', $e::class)];
        }
    }

    private function html(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

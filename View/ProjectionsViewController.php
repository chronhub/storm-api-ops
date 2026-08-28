<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use ApiPlatform\Metadata\GetCollection;
use JsonException;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\ApiOps\State\ProjectionsProvider;
use Storm\Contracts\Chronicler\StorageFailure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

use function is_array;

/**
 * The projections screen, rendering what `/_storm/projections` serves as JSON.
 *
 * @see ProjectionsProvider the read this renders
 */
#[AsController]
final readonly class ProjectionsViewController
{
    public function __construct(
        private ProjectionsProvider $projections,
        private ProjectionsView $view,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws JsonException when a projection's stored row is malformed
     * @throws StorageFailure when the safe-head or checkpoint read fails at the storage level
     */
    public function __invoke(Request $request): Response
    {
        $answered = $this->projections->provide(new GetCollection);

        // the collection operation answers a list; the item shape belongs to the other window, and a
        // screen that rendered it would be reading a route it was not given
        /** @var list<ProjectionResource> $projections */
        $projections = is_array($answered) ? $answered : [];

        return new Response(
            $this->view->render($projections, ViewRefresh::secondsFrom($request)),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}

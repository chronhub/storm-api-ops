<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use ApiPlatform\Metadata\Get;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\State\BacklogProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The backlog screen, rendering what `/_storm/backlog` serves as JSON.
 *
 * It calls the provider IN PROCESS rather than fetching its own endpoint. A screen that asked its
 * own application over HTTP would need the caller's credentials to reach itself, would double every
 * read, and would answer a broken page for a firewall change that broke nothing.
 *
 * @see BacklogProvider the read this renders
 */
#[AsController]
final readonly class BacklogViewController
{
    public function __construct(
        private BacklogProvider $backlog,
        private BacklogView $view,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    public function __invoke(Request $request): Response
    {
        // the provider carries the actor gate, so the screen inherits the surface's posture instead
        // of re-stating it; a second gate here would be a second thing to keep true
        $backlog = $this->backlog->provide(new Get);

        return new Response(
            $this->view->render($backlog, ViewRefresh::secondsFrom($request)),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}

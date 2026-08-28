<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Exception;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\State\OutboxFailedProvider;
use Storm\ApiOps\State\PageWindow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The dead-letter screen, rendering what `/_storm/outbox/failed` serves as JSON.
 *
 * The provider is called in process and keeps its own window arithmetic, so the page and the JSON
 * answer the same question with the same clamp: a screen that re-derived the window would be a
 * second place for the cap to drift.
 *
 * @see OutboxFailedProvider the read this renders
 */
#[AsController]
final readonly class OutboxFailedViewController
{
    public function __construct(
        private OutboxFailedProvider $failed,
        private OutboxFailedView $view,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws Exception on a DBAL read failure
     */
    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $filters */
        $filters = $request->query->all();

        $rows = $this->failed->provide(new GetCollection, [], ['filters' => $filters]);

        return new Response(
            // the window is read back through the same arithmetic the provider applied, never from
            // the raw query: the page must echo what was SERVED, not what was asked for
            $this->view->render($rows, PageWindow::afterPosition($filters), PageWindow::limit($filters), ViewRefresh::secondsFrom($request)),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use ApiPlatform\Metadata\GetCollection;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StreamsProvider;
use Storm\Contracts\Chronicler\StorageFailure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The stream directory screen, rendering what `/_storm/streams` serves as JSON.
 *
 * The provider is called in process and keeps its own window arithmetic, so the page and the JSON
 * answer the same question with the same clamp: a screen that re-derived the window would be a
 * second place for the cap to drift.
 *
 * The window here CLAMPS where the saga directory refuses, and the difference is deliberate. This
 * is a keyset browse whose cursor is a stream name a person pastes; a mistyped page size costs the
 * operator a default window, never the screen. A narrowing parameter is the other case, and it
 * lives on the surface that owns one.
 */
#[AsController]
final readonly class StreamsViewController
{
    public function __construct(
        private StreamsProvider $streams,
        private StreamsView $view,
    ) {}

    /**
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws StorageFailure on a store failure of the browse
     */
    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $filters */
        $filters = $request->query->all();

        $streams = $this->streams->provide(new GetCollection, [], ['filters' => $filters]);

        return new Response(
            // read back through the same arithmetic the provider applied, never from the raw query:
            // the page must echo what was SERVED, not what was asked for
            $this->view->render(
                $streams,
                PageWindow::category($filters) ?? '',
                PageWindow::afterStream($filters),
                PageWindow::limit($filters),
                ViewRefresh::secondsFrom($request),
            ),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}

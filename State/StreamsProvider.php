<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\StreamResource;
use Storm\Chronicler\Directory\StreamDirectory;
use Storm\Chronicler\Directory\StreamHead;
use Storm\Contracts\Chronicler\StorageFailure;

/**
 * The stream directory as an ops collection: windowed, name-ordered, resumable by the last
 * stream name seen. A category that matches nothing answers an empty window; for a browser of
 * the store, "nothing there" is an answer, never a fault.
 *
 * @implements ProviderInterface<StreamResource>
 */
final readonly class StreamsProvider implements ProviderInterface
{
    public function __construct(
        private StreamDirectory $directory,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<StreamResource>
     *
     * @throws StorageFailure on a store failure of the browse
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->gate->assertOwnedIdentityForRead('streams.read', '*');

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        return array_map(
            static fn (StreamHead $head): StreamResource => new StreamResource($head->stream, $head->lastVersion),
            $this->directory->browse(
                PageWindow::category($filters),
                PageWindow::afterStream($filters),
                PageWindow::limit($filters),
            ),
        );
    }
}

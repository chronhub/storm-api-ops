<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Projector\Store\ProjectionCatalog;

use function array_map;
use function is_string;

/**
 * Projections as an ops collection or item, the same `ProjectionStore` row the console status
 * command renders, enriched per row with the waiter's lag / at-head answers and the lease's live
 * truth. Small N by construction, a declared population, so the per-row reads are the honest price
 * of a truthful snapshot, not an N+1 to engineer away.
 *
 * @implements ProviderInterface<ProjectionResource>
 */
final readonly class ProjectionsProvider implements ProviderInterface
{
    public function __construct(
        private ProjectionCatalog $store,
        private ProjectionResourceFactory $factory,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<ProjectionResource>|ProjectionResource|null
     *
     * @throws JsonException when a projection's stored row is malformed
     * @throws StorageFailure when the safe-head or checkpoint read fails at the storage level
     * @throws Exception on a raw DBAL failure of the row or lease reads
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|ProjectionResource|null
    {
        $name = $uriVariables['name'] ?? null;
        $this->gate->assertOwnedIdentityForRead('projections.read', is_string($name) ? $name : '*');

        if (is_string($name)) {
            $row = $this->store->findRow($name);

            return $row === null ? null : $this->factory->fromRow($row);
        }

        return array_map($this->factory->fromRow(...), $this->store->all());
    }
}

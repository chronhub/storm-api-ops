<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use Doctrine\DBAL\Exception;
use JsonException;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Projector\Freshness\ProjectionWaiter;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionRow;

/**
 * One projection row rendered as its ops resource: the stored row enriched with the waiter's
 * measured lag / at-head answers and the lease's SQL-clock live truth. Shared by the read
 * provider and the mutation processor; a mutation answers the same fresh truth a read would.
 */
final readonly class ProjectionResourceFactory
{
    public function __construct(
        private ProjectionCatalog $store,
        private ProjectionWaiter $waiter,
    ) {}

    /**
     * @throws JsonException when the stored row is malformed
     * @throws StorageFailure when the safe-head or checkpoint read fails at the storage level
     * @throws Exception on a raw DBAL failure of the lease read
     */
    public function fromRow(ProjectionRow $row): ProjectionResource
    {
        return new ProjectionResource(
            name: $row->name,
            status: $row->status->value,
            pauseUntil: $row->pauseUntil,
            position: $row->lastPosition,
            lag: $this->waiter->lag($row->name),
            atHead: $this->waiter->isAtHead($row->name),
            mode: $row->mode,
            categories: $row->categories,
            eventClasses: $row->eventClasses,
            sourceStream: $row->sourceStream?->toString(),
            sourceRevision: $row->sourceRevision,
            leaseOwner: $row->leaseOwner,
            leaseLive: $row->leaseOwner === null ? null : $this->store->hasLiveLease($row->name),
            leaseUntil: $row->leaseUntil,
            heartbeatAt: $row->lastHeartbeatAt,
            targetStream: $row->targetStream?->toString(),
            targetPrefix: $row->targetPrefix,
            generation: $row->generation,
            failedAt: $row->failedAt,
            errorClass: $row->errorClass,
            errorMessage: $row->errorMessage,
        );
    }
}

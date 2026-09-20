<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use Override;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;

/**
 * {@inheritDoc}
 *
 * Reads the saga snapshots for the correlation and keeps only what a consumer asked for, the child
 * ids. The snapshot shape stops here rather than reaching a page: a consumer that walked
 * `$snapshot->children` would be coupled to the coordination module's record for one string per row.
 *
 * The gateway is required rather than optional, and that is not an oversight. Its import is gated on
 * the saga package being PRESENT on disk, and this package requires it, so an installation carrying
 * this adapter carries the gateway too. `SagasProvider` takes the same argument non-nullable.
 */
final readonly class SagaCorrelationLineage implements CorrelationLineage
{
    public function __construct(
        private SagaInspectionGateway $gateway,
    ) {}

    #[Override]
    public function childrenOf(string $correlationId): array
    {
        $ids = [];

        foreach ($this->gateway->inspect($correlationId, null) as $snapshot) {
            foreach ($snapshot->children as $child) {
                $ids[] = $child->correlationId;
            }
        }

        return $ids;
    }
}

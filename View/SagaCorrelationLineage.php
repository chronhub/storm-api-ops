<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Override;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;

/**
 * {@inheritDoc}
 *
 * Reads the saga snapshots for the correlation and keeps only what a screen asked for, the child
 * ids. The snapshot shape stops here rather than reaching the view: a screen that walked
 * `$snapshot->children` would be coupled to the coordination module's record for one string per row.
 *
 * The gateway is required rather than optional, and that is not an oversight. Its import is gated on
 * the saga package being PRESENT on disk, and this package requires it, so an installation carrying
 * this adapter carries the gateway too. `SagasProvider` has taken the same argument non-nullable
 * since before this seam existed.
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

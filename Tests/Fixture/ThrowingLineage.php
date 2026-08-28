<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Override;
use RuntimeException;
use Storm\ApiOps\View\CorrelationLineage;

/**
 * A lineage whose store is unreachable, the shape a screen must degrade around rather than answer
 * with a 500.
 */
final readonly class ThrowingLineage implements CorrelationLineage
{
    #[Override]
    public function childrenOf(string $correlationId): array
    {
        throw new RuntimeException('the saga store is unreachable');
    }
}

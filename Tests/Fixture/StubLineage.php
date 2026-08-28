<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Override;
use Storm\ApiOps\View\CorrelationLineage;

/**
 * A lineage that answers the same children whatever it is asked, which is what a screen test needs:
 * the walk belongs to the adapter's own suite, the widening to the screen's.
 */
final readonly class StubLineage implements CorrelationLineage
{
    /**
     * @param  list<string>  $children
     */
    public function __construct(private array $children) {}

    #[Override]
    public function childrenOf(string $correlationId): array
    {
        return $this->children;
    }
}

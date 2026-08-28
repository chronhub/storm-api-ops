<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Storm\Aggregate\ProvideAggregateIdentity;
use Storm\Contracts\Aggregate\AggregateIdentity;
use Symfony\Component\Uid\Uuid;

/**
 * A typed identity for the aggregates this module's ops reads introspect. Local to ApiOps like
 * every other fixture here: a module's suite builds its own, so a change to another module's
 * fixture cannot move a verdict about this one.
 */
final readonly class OpsAggregateId implements AggregateIdentity
{
    use ProvideAggregateIdentity;

    public static function generate(): static
    {
        return new self(Uuid::v7());
    }
}

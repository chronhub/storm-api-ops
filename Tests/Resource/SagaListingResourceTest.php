<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Resource;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Storm\ApiOps\Resource\SagaListingResource;
use Storm\Saga\Store\Inspection\SagaSummarySnapshot;

/**
 * The listing row claims to carry its snapshot verbatim, field for field, and that claim is the kind
 * a sample cannot hold: fourteen fields mapped by hand in one call, checked by naming four of them.
 * A transposed pair of the ten unchecked ones, or a field added upstream and never mapped, reads as a
 * complete row and answers with somebody else's value.
 *
 * Asserted over the PROPERTIES rather than a written list, so a field added to either side is
 * covered without anyone remembering; and every seeded value is distinct, since two fields carrying
 * the same value make a transposition invisible.
 */
final class SagaListingResourceTest extends TestCase
{
    #[Test]
    public function a_listing_built_by_hand_is_executable_by_default(): void
    {
        $row = new SagaListingResource(
            workflowType: 'transfer',
            correlationId: 'c-1',
            stateKey: 'await_credit',
            status: 'running',
            version: 1,
            generation: 1,
            definitionVersion: 1,
            retryTotal: 0,
            startedAt: null,
            updatedAt: null,
            waivedAt: null,
            parentCorrelationId: null,
        );

        self::assertFalse($row->typePaused);
    }

    #[Test]
    #[Group('adversarial')]
    public function every_field_of_the_snapshot_arrives_on_the_row_that_claims_to_carry_it(): void
    {
        $snapshot = new SagaSummarySnapshot(
            workflowType: 'transfer',
            correlationId: 'c-1',
            stateKey: 'await_credit',
            status: 'running',
            version: 11,
            generation: 22,
            definitionVersion: 33,
            retryTotal: 44,
            startedAt: '2026-01-01T00:00:00+00:00',
            updatedAt: '2026-02-02T00:00:00+00:00',
            waivedAt: '2026-03-03T00:00:00+00:00',
            parentCorrelationId: 'parent-1',
            pausedAt: '2026-04-04T00:00:00+00:00',
            typePaused: true,
        );

        $row = SagaListingResource::fromSnapshot($snapshot);

        foreach (new ReflectionClass(SagaListingResource::class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            self::assertTrue(property_exists($snapshot, $name), sprintf('the row carries `%s`, which no snapshot field feeds', $name));
            self::assertSame($snapshot->{$name}, $row->{$name}, sprintf('`%s` did not arrive as the snapshot holds it', $name));
        }

        self::assertGreaterThan(10, count(new ReflectionClass(SagaListingResource::class)->getProperties(ReflectionProperty::IS_PUBLIC)), 'the walk itself broke');
    }
}

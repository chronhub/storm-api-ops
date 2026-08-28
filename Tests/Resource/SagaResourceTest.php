<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Resource;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Storm\ApiOps\Resource\SagaResource;
use Storm\Saga\Store\Inspection\ChildSnapshot;
use Storm\Saga\Store\Inspection\OutboxSnapshot;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Store\Inspection\TimerSnapshot;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;

final class SagaResourceTest extends TestCase
{
    #[Test]
    public function a_resource_built_by_hand_is_executable_by_default(): void
    {
        $resource = new SagaResource(
            workflowType: 'payment',
            stateKey: 'await',
            status: 'running',
            version: 1,
            startedAt: null,
            retries: [],
            compensations: [],
            timers: [],
            outbox: [],
        );

        self::assertFalse($resource->typePaused);
    }

    #[Test]
    public function the_four_collections_cross_as_their_element_s_own_to_array(): void
    {
        // the wire contract of the ops surface: compensations, timers, outbox and children each
        // cross FLATTENED through an explicit allowlist. Today every snapshot key is admitted, so
        // the mapped record equals toArray(); the sibling key-set test below is what keeps that
        // equality honest, turning an upstream addition into a failing build instead of a silent
        // new public wire field.
        //
        // Every collection holds ONE element on purpose: array_map over an empty array is
        // indistinguishable from the array itself, which is exactly what left this unpinned. And
        // the assertion compares against toArray() rather than merely checking arrayness, which
        // the property type already guarantees and would assert nothing.
        $snapshot = $this->snapshot();
        $resource = SagaResource::fromSnapshot($snapshot);

        $this->assertSame($snapshot->timers[0]->toArray(), $resource->timers[0]);
        $this->assertSame($snapshot->outbox[0]->toArray(), $resource->outbox[0]);
        $this->assertSame($snapshot->compensations[0]->toArray(), $resource->compensations[0]);
        $this->assertSame($snapshot->children[0]->toArray(), $resource->children[0]);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_upstream_snapshot_addition_must_be_admitted_into_the_allowlist(): void
    {
        // the guard M-shaped pass-throughs cannot give: the snapshots belong to the Saga module,
        // and OutboxSnapshot already publishes last_error, app-authored text, so a key IT adds
        // must be a conscious ApiOps admission; this comparison fails the build the day upstream
        // adds one, which is the decision point
        $snapshot = $this->snapshot();

        $this->assertSame(SagaResource::COMPENSATION_KEYS, array_keys($snapshot->compensations[0]->toArray()));
        $this->assertSame(SagaResource::TIMER_KEYS, array_keys($snapshot->timers[0]->toArray()));
        $this->assertSame(SagaResource::OUTBOX_KEYS, array_keys($snapshot->outbox[0]->toArray()));
        $this->assertSame(SagaResource::CHILD_KEYS, array_keys($snapshot->children[0]->toArray()));
    }

    #[Test]
    public function the_top_level_carries_the_console_snapshot_field_for_field(): void
    {
        // the parity both sides claim: SagaSnapshot states that the console --json and the ops
        // HTTP surface serve THIS, and the sibling history endpoint asks the caller for the very
        // generation this view must therefore expose; updated_at is the first question a halted
        // saga raises, and exposed is the module's designed window onto app state
        $resource = SagaResource::fromSnapshot($this->snapshot());

        $this->assertSame('2026-08-05T09:30:00.000000+00:00', $resource->updatedAt);
        $this->assertSame(2, $resource->generation);
        $this->assertSame(3, $resource->definitionVersion);
        $this->assertSame(4, $resource->stateVersion);
        $this->assertSame(5, $resource->retryTotal);
        $this->assertSame('2026-08-05T09:45:00.000000+00:00', $resource->waivedAt);
        $this->assertSame(['balance' => 42], $resource->exposed);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_allowlist_actually_drops_what_it_does_not_admit(): void
    {
        // no real snapshot can carry a foreign key today, which is exactly what would let a
        // removed filter pass every other test; the filter is exercised directly so the guard
        // stays a guard rather than a decoration
        $picked = new ReflectionMethod(SagaResource::class, 'pick')->invoke(null, ['status' => 'pending', 'smuggled' => 'app-secret'], ['status']);

        $this->assertSame(['status' => 'pending'], $picked);
    }

    private function snapshot(): SagaSnapshot
    {
        return new SagaSnapshot(
            workflowType: 'payment',
            stateKey: 'await',
            status: 'running',
            version: 3,
            startedAt: '2026-08-05T09:00:00.000000+00:00',
            updatedAt: '2026-08-05T09:30:00.000000+00:00',
            generation: 2,
            definitionVersion: 3,
            retryTotal: 5,
            waivedAt: '2026-08-05T09:45:00.000000+00:00',
            retries: [],
            compensations: [new CompensationRecord('debit', CompensationStatus::cases()[0])],
            timers: [new TimerSnapshot(1, 'deadline', 'await', '2026-08-05T10:00:00.000000+00:00', null)],
            outbox: [new OutboxSnapshot('pending', 'Charge', 'command.bus', 0, '2026-08-05T09:00:00.000000+00:00', null, 'await', 1, 1)],
            children: [new ChildSnapshot('refund', 'c-child', 'running')],
            stateVersion: 4,
            exposed: ['balance' => 42],
        );
    }
}

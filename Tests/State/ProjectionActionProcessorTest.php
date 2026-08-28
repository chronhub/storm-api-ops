<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Post;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\ProjectionNotFound;
use Storm\ApiOps\Error\ProjectionTransitionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\ProjectionActionProcessor;
use Storm\ApiOps\State\ProjectionResourceFactory;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Chronicler\Store\StreamReader;
use Storm\EventLinks\DerivedStreamHead;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Freshness\ProjectionWaiter;
use Storm\Projector\Link\EventLinkWriter;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionLane;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionLifecycleStore;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Storm\Projector\Store\ProjectionStore;

final class ProjectionActionProcessorTest extends TestCase
{
    #[Test]
    public function an_unknown_ops_action_is_a_wiring_fault_audited_as_refused(): void
    {
        // the guard lives inside the audited apply, so unlike the saga twins the refusal leaves a
        // trace: the fault names the stray action, the audit records it refused before the rethrow,
        // and no transition, retry or reset verb ran; the rig's management rides an EMPTY registry,
        // so a default arm reaching retry or reset would surface as UnknownProjection, not this fault
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->expects($this->never())->method('pause');
        $store->expects($this->never())->method('resume');
        $log = new RecordingLog;

        $refused = '';

        try {
            $this->processor($store, $log)->process(null, $this->operation('rewind'), ['name' => 'account_balance']);
            $this->fail('an undeclared ops action must be refused as a wiring fault');
        } catch (LogicException $e) {
            $this->assertStringContainsString('"rewind"', $e->getMessage());
            $this->assertStringContainsString('not a missing projection', $e->getMessage());
            $refused = 'refused: '.$e->getMessage();
        }

        $this->assertSame([], $log->applied());
        $refusals = array_values(array_filter($log->records, static fn (array $r): bool => str_starts_with((string) ($r['context']['outcome'] ?? ''), 'refused:')));
        $this->assertCount(1, $refusals);

        // the whole sentence, not just its prefix: a trail that says `refused:` and nothing else
        // records that something was refused without ever saying what, which is the silence this
        // record exists to break
        $this->assertSame($refused, $refusals[0]['context']['outcome']);

        // and it must name WHAT was refused: the projection from the path and the action from the
        // operation, both read out of the request rather than assumed
        $this->assertSame('account_balance', $refusals[0]['context']['subject']);
        $this->assertSame('rewind', $refusals[0]['context']['action']);
    }

    // rig

    #[Test]
    public function an_anonymous_projection_action_is_refused_before_the_store(): void
    {
        // the gate runs before the lifecycle store, so an unnamed caller cannot learn whether the
        // projection exists, and the refusal is audited rather than silent
        $log = new RecordingLog;
        $store = $this->createStub(ProjectionLifecycleStore::class);

        try {
            $this->processor($store, $log, allowAnonymous: false)
                ->process(null, $this->operation('pause'), ['name' => 'account_balance']);
            self::fail('an unnamed caller must not reach the lifecycle store');
        } catch (AnonymousMutationRefused $e) {
            $this->assertStringContainsString('no authenticated actor', $e->getMessage());
        }

        $this->assertSame([], $log->applied());
        $this->assertSame('refused: anonymous mutation', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function a_named_decline_is_audited_refused_and_an_outage_is_audited_failed(): void
    {
        // the audit vocabulary: a refusal is a DECLINE, the module's own or a wiring fault; an
        // infrastructure outage is a FAILURE, and a trail calling it refused would read as an
        // operator's verb declined when the store simply went away
        $outage = new class('SQLSTATE[08006] connection refused') extends RuntimeException implements DbalException {};
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->method('pause')->willThrowException($outage);
        $log = new RecordingLog;

        try {
            $this->processor($store, $log)->process(null, $this->operation('pause'), ['name' => 'account_balance']);
            $this->fail('the outage must propagate');
        } catch (RuntimeException) {
        }

        $outcomes = array_map(static fn (array $r): string => (string) ($r['context']['outcome'] ?? ''), $log->records);
        $this->assertSame(['failed: SQLSTATE[08006] connection refused'], $outcomes);
    }

    #[Test]
    public function a_pause_on_a_non_pausable_status_is_refused_before_the_store_writes(): void
    {
        // the pre-read guard: the state machine's own predicate declines, the store is untouched
        $failed = $this->rowWithStatus(ProjectionStatus::Failed);
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($failed);
        $store->expects($this->never())->method('pause');

        $this->expectException(ProjectionTransitionRefused::class);
        // the whole sentence: the refusal names the verb, the blocking status, and, for a failed
        // projection, the two recovery verbs an operator reaches for next
        $this->expectExceptionMessageIsOrContains('Cannot "pause" a projection in status "failed". A failed projection recovers via "retry" (resume from the checkpoint) or "reset" (replay from scratch).');

        $this->processor($store, new RecordingLog)->process(null, $this->operation('pause'), ['name' => 'account_balance']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_raced_pause_refuses_and_the_cas_carries_the_guards_own_states(): void
    {
        // the compare-and-set carries the SAME predicate the pre-read validated: Idle and Running,
        // the two pausable states, pinned as literals so a drifted statesWhere is a failing test,
        // never a CAS quietly wider or narrower than the guard; and a false write, the raced row,
        // is a refusal, not a silent success
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->expects($this->once())
            ->method('pause')
            ->willReturnCallback(static function (string $name, ?int $forSeconds, ProjectionStatus ...$states): bool {
                // asserted INSIDE the call: with() only constrains the positions it declares, so a
                // widened variadic tail would slide past it unchecked
                self::assertSame('account_balance', $name);
                self::assertNull($forSeconds);
                self::assertSame([ProjectionStatus::Idle, ProjectionStatus::Running], $states, "the CAS carries exactly the guard's pausable states");

                return false;
            });

        $this->expectException(ProjectionTransitionRefused::class);
        $this->expectExceptionMessageIsOrContains('Cannot "pause" projection "account_balance": its status changed while the request was being applied, so nothing was written. Re-read it and retry.');

        $this->processor($store, new RecordingLog)->process(null, $this->operation('pause'), ['name' => 'account_balance']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_projection_retired_between_the_verb_and_the_read_back_is_reported_not_dressed_up(): void
    {
        // the race no HTTP request can be made to exhibit: the verb applied, then a retire won the
        // read-back. Serving the PRE-mutation row as fresh truth would hand an operator a snapshot
        // dated after a change it does not carry, of a projection that no longer exists; the 404 is
        // the same refusal this surface makes everywhere else
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $reads = 0;
        $store->method('findRow')->willReturnCallback(function () use (&$reads): ?ProjectionRow {
            // the pre-read finds it, the read-back does not: one stub, two answers, which is the
            // whole shape of the race
            return ++$reads === 1 ? $this->row() : null;
        });
        $store->expects($this->once())->method('pause')->willReturn(true);
        $log = new RecordingLog;

        try {
            $this->processor($store, $log)->process(null, $this->operation('pause'), ['name' => 'account_balance']);
            self::fail('a retired projection must surface as ProjectionNotFound');
        } catch (ProjectionNotFound $e) {
            $this->assertSame('No projection "account_balance".', $e->getMessage());
        }

        // the verb HAPPENED and stays audited as applied: the throw reports a failure to SHOW the
        // result, it never un-records the act
        $this->assertCount(1, $log->applied());
    }

    private function processor(ProjectionLifecycleStore $store, RecordingLog $log, bool $allowAnonymous = true): ProjectionActionProcessor
    {
        $audit = new OpsAuditLog($log);
        // the factory and the management are only reached by a mutation that applied; real
        // instances over stubs satisfy their final types
        $factory = new ProjectionResourceFactory(
            $this->createStub(ProjectionCatalog::class),
            new ProjectionWaiter($this->createStub(ProjectionCatalog::class), $this->createStub(StreamReader::class), new ProjectionRegistry, $this->createStub(DerivedStreamHead::class), $this->createStub(DerivedStreamRevision::class)),
        );
        $lane = new ProjectionLane($this->createStub(ProjectionStore::class), $this->createStub(Connection::class));
        $management = new ProjectionManagement(new ProjectionRegistry, new ProjectionLanes($lane, $lane), new EventLinkWriter);

        return new ProjectionActionProcessor($store, $management, $factory, $audit, new OpsActorGate($audit, null, allowAnonymous: $allowAnonymous));
    }

    private function operation(string $action): Post
    {
        return new Post(extraProperties: ['storm_ops_action' => $action]);
    }

    private function row(): ProjectionRow
    {
        return $this->rowWithStatus(ProjectionStatus::Idle);
    }

    private function rowWithStatus(ProjectionStatus $status): ProjectionRow
    {
        return new ProjectionRow(
            name: 'account_balance',
            status: $status,
            lastPosition: 0,
            mode: 'categories',
            categories: ['account'],
            eventClasses: [],
            sourceStream: null,
            sourceRevision: 0,
            targetStream: null,
            targetPrefix: null,
            leaseOwner: null,
            leaseUntil: null,
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 1,
        );
    }
}

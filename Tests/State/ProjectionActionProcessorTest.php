<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\ProjectionNotFound;
use Storm\ApiOps\Error\ProjectionTransitionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\ApiOps\State\OpsRoute;
use Storm\ApiOps\State\ProjectionActionProcessor;
use Storm\ApiOps\State\ProjectionResourceFactory;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Chronicler\Store\StreamReader;
use Storm\EventLinks\DerivedStreamHead;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Exception\InvalidStatusTransition;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionNotFailed;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
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
use Throwable;

final class ProjectionActionProcessorTest extends TestCase
{
    #[Test]
    public function an_unknown_ops_action_is_a_wiring_fault_that_leaves_no_audit_line(): void
    {
        // the fault names the stray action, and no transition, retry or reset verb ran; like the saga
        // twins, a fault of the resource declaration mutated nothing and leaves no line. The rig's
        // management rides an empty registry, so a default arm reaching retry or reset would surface
        // as UnknownProjection, not this fault
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->expects($this->never())->method('pause');
        $store->expects($this->never())->method('resume');
        $log = new RecordingLog;

        try {
            $this->processor($store, $log)->process(null, $this->operation('rewind'), ['name' => 'account_balance']);
            $this->fail('an undeclared ops action must be refused as a wiring fault');
        } catch (LogicException $e) {
            $this->assertStringContainsString('"rewind"', $e->getMessage());
            $this->assertStringContainsString('not a missing projection', $e->getMessage());
        }

        $this->assertSame([], $log->records);
    }

    #[Test]
    public function an_unknown_ops_action_on_a_missing_projection_leaves_no_audit_line(): void
    {
        // the verb is judged before the row lookup: a missing projection must not turn the wiring
        // fault into a caller's 404 and a `refused:` line
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn(null);
        $log = new RecordingLog;
        $escaped = null;

        try {
            $this->processor($store, $log)->process(null, $this->operation('rewind'), ['name' => 'ghost']);
        } catch (Throwable $e) {
            $escaped = $e;
        }

        $this->assertSame([], $log->records);
        $this->assertInstanceOf(LogicException::class, $escaped);
        $this->assertStringContainsString('"rewind"', $escaped->getMessage());
    }

    #[Test]
    public function every_verb_the_resource_declares_passes_the_verb_check(): void
    {
        // on a missing projection, each declared verb meets the lookup's 404, or reset its unknown
        // projection through the rig's empty registry, never the wiring fault of an unknown verb
        $resource = new ReflectionClass(ProjectionResource::class)->getAttributes(ApiResource::class)[0]->newInstance();
        $verbs = [];
        foreach ($resource->getOperations() ?? [] as $operation) {
            if ($operation->getProcessor() === ProjectionActionProcessor::class) {
                $verbs[] = OpsRoute::string($operation->getExtraProperties(), 'storm_ops_action');
            }
        }

        $this->assertSame(['pause', 'resume', 'stop', 'retry', 'reset'], $verbs);

        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn(null);

        foreach ($verbs as $verb) {
            $escaped = null;

            try {
                $this->processor($store, new RecordingLog)->process(null, $this->operation($verb), ['name' => 'account_balance']);
            } catch (Throwable $e) {
                $escaped = $e;
            }

            $this->assertInstanceOf($verb === 'reset' ? UnknownProjection::class : ProjectionNotFound::class, $escaped, $verb);
        }
    }

    #[Test]
    public function a_code_fault_out_of_a_verb_leaves_no_audit_line(): void
    {
        // the store's guard against an empty compare-and-set is a `LogicException` raised before any
        // write: nothing mutated, and the audit says nothing of it
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->method('pause')->willThrowException(InvalidStatusTransition::withoutExpectedStates('account_balance', ProjectionStatus::Paused));
        $log = new RecordingLog;

        try {
            $this->processor($store, $log)->process(null, $this->operation('pause'), ['name' => 'account_balance']);
            $this->fail('the code fault must propagate');
        } catch (InvalidStatusTransition) {
        }

        $this->assertSame([], $log->records);
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
    public function a_dbal_outage_is_audited_as_failed(): void
    {
        // an infrastructure outage is a failure: a trail calling it refused would read as an
        // operator's verb declined when the store simply went away
        $outage = new class('SQLSTATE[08006] connection refused') extends RuntimeException implements DbalException {};
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->method('pause')->willThrowException($outage);

        $this->assertSame(['failed: RuntimeException@anonymous: SQLSTATE[08006] connection refused'], $this->outcomesOfAThrowingAction($store, 'pause', $outage));
    }

    #[Test]
    public function a_non_dbal_failure_is_audited_as_failed(): void
    {
        // a failure outside DBAL, a driver of the app's own store or an output that could not be
        // cleared, is no guard's decline either: the verb was accepted and did not complete
        $failure = new RuntimeException('projection output could not be cleared');
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->method('pause')->willThrowException($failure);

        $this->assertSame(['failed: RuntimeException: projection output could not be cleared'], $this->outcomesOfAThrowingAction($store, 'pause', $failure));
    }

    #[Test]
    public function a_failure_chaining_its_cause_keeps_the_cause_in_its_audit_line(): void
    {
        // a failure that wraps its cause without quoting it would otherwise leave a line saying what
        // failed and never why
        $failure = new RuntimeException('reset failed', previous: new RuntimeException('the store went away'));
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->method('pause')->willThrowException($failure);

        $outcomes = $this->outcomesOfAThrowingAction($store, 'pause', $failure);

        $this->assertCount(1, $outcomes);
        $this->assertStringStartsWith('failed: ', $outcomes[0]);
        $this->assertStringContainsString('the store went away', $outcomes[0]);
    }

    #[Test]
    public function a_named_decline_is_audited_as_refused(): void
    {
        // the declines the resource answers with a 4xx stay refusals, whichever layer raises them: the
        // module's raced transition and the projector's guards, `UnsupportedProjection` among them, a
        // `LogicException` the wiring-fault arm must not swallow
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->method('pause')->willReturn(false);
        $registered = $this->createStub(QueryProjection::class);
        $registered->method('name')->willReturn('account_balance');
        $registry = new ProjectionRegistry([$registered]);

        // retry reaches its in-transaction guard on a failed row while a worker holds the lease
        $leased = $this->createStub(ProjectionStore::class);
        $leased->method('findRow')->willReturn($this->rowWithStatus(ProjectionStatus::Failed));
        $leased->method('lockAndAssertNotRunning')->willThrowException(ProjectionBusy::running('account_balance'));
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (Closure $work): mixed => $work());

        $declines = [
            ProjectionTransitionRefused::raced('pause', 'account_balance'),
            UnknownProjection::named('account_balance'),
            ProjectionNotFailed::cannotRetry('account_balance', null),
            ProjectionBusy::running('account_balance'),
            UnsupportedProjection::notPersistent('account_balance'),
        ];

        $outcomes = [
            ...$this->outcomesOfAThrowingAction($store, 'pause', $declines[0]),
            ...$this->outcomesOfAThrowingAction($store, 'retry', $declines[1]),
            ...$this->outcomesOfAThrowingAction($store, 'retry', $declines[2], $registry),
            ...$this->outcomesOfAThrowingAction($store, 'retry', $declines[3], $registry, new ProjectionLane($leased, $connection)),
            ...$this->outcomesOfAThrowingAction($store, 'reset', $declines[4], $registry),
        ];

        $this->assertSame(array_map(static fn (Throwable $decline): string => 'refused: '.$decline->getMessage(), $declines), $outcomes);
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
        // the compare-and-set carries the same predicate the pre-read validated: Idle and Running,
        // the two pausable states, pinned as literals so a drifted statesWhere is a failing test,
        // never a CAS quietly wider or narrower than the guard; and a false write, the raced row,
        // is a refusal, not a silent success
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $store->expects($this->once())
            ->method('pause')
            ->willReturnCallback(static function (string $name, ?int $forSeconds, ProjectionStatus ...$states): bool {
                // asserted inside the call: with() only constrains the positions it declares, so a
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

    /**
     * @param  non-empty-string  $write
     * @param  list<ProjectionStatus>  $guarded
     */
    #[Test]
    #[DataProvider('resumeAndStop')]
    public function a_resume_or_a_stop_reaches_its_own_write_with_the_guards_states(string $verb, ProjectionStatus $from, string $write, array $guarded, bool|ProjectionStatus $landed): void
    {
        // each verb has its own arm in apply(): a missing arm throws, a swapped one calls another
        // write, and the compare-and-set carries the states the guard validated
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->rowWithStatus($from));
        $store->expects($this->once())
            ->method($write)
            ->willReturnCallback(static function (string $name, ProjectionStatus ...$states) use ($guarded, $landed): bool|ProjectionStatus {
                self::assertSame('account_balance', $name);
                self::assertSame($guarded, $states, "the CAS carries exactly the guard's states");

                return $landed;
            });
        $log = new RecordingLog;

        $this->processor($store, $log)->process(null, $this->operation($verb), ['name' => 'account_balance']);

        $this->assertCount(1, $log->applied());
    }

    /**
     * @param  non-empty-string  $write
     */
    #[Test]
    #[DataProvider('resumeAndStopWrites')]
    public function a_raced_resume_or_stop_refuses_rather_than_answering_applied(string $verb, ProjectionStatus $from, string $write): void
    {
        // a write that lands nothing, a false resume or a null stop, is the raced row: a refusal
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->rowWithStatus($from));
        $store->method($write)->willReturn($write === 'resume' ? false : null);
        $raced = ProjectionTransitionRefused::raced($verb, 'account_balance');

        $this->assertSame(['refused: '.$raced->getMessage()], $this->outcomesOfAThrowingAction($store, $verb, $raced));
    }

    /**
     * @param  non-empty-string  $write
     */
    #[Test]
    #[DataProvider('resumeAndStopWrites')]
    public function a_resume_or_stop_its_status_forbids_is_refused_before_the_store_writes(string $verb, ProjectionStatus $from, string $write): void
    {
        // the pre-read guard declines on the state machine's own predicate, a failed row accepting
        // neither verb, and the store is untouched
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->rowWithStatus(ProjectionStatus::Failed));
        $store->expects($this->never())->method($write);

        $this->expectException(ProjectionTransitionRefused::class);
        $this->expectExceptionMessageIsOrContains(sprintf('Cannot "%s" a projection in status "failed".', $verb));

        $this->processor($store, new RecordingLog)->process(null, $this->operation($verb), ['name' => 'account_balance']);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     ProjectionStatus,
     *     non-empty-string,
     *     list<ProjectionStatus>,
     *     bool|ProjectionStatus,
     * }>
     */
    public static function resumeAndStop(): iterable
    {
        yield 'resume' => ['resume', ProjectionStatus::Paused, 'resume', [ProjectionStatus::Paused], true];
        yield 'stop' => ['stop', ProjectionStatus::Running, 'requestStop', [ProjectionStatus::Running, ProjectionStatus::Stopping], ProjectionStatus::Stopping];
    }

    /**
     * @return iterable<string, array{string, ProjectionStatus, non-empty-string}>
     */
    public static function resumeAndStopWrites(): iterable
    {
        yield 'resume' => ['resume', ProjectionStatus::Paused, 'resume'];
        yield 'stop' => ['stop', ProjectionStatus::Running, 'requestStop'];
    }

    #[Test]
    public function a_verb_apply_does_not_know_never_answers_applied(): void
    {
        // `drain` stands for a verb a change admits in the judge of process() and forgets in apply():
        // the judge refuses it today as a wiring fault, and once admitted, apply()'s match throws and
        // the throw is audited as failed; neither path writes applied over an unmutated row
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn($this->row());
        $log = new RecordingLog;
        $escaped = null;

        try {
            $this->processor($store, $log)->process(null, $this->operation('drain'), ['name' => 'account_balance']);
        } catch (Throwable $e) {
            $escaped = $e;
        }

        $this->assertNotNull($escaped);
        $this->assertSame([], $log->applied());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_projection_retired_between_the_verb_and_the_read_back_is_reported_not_dressed_up(): void
    {
        // the race no HTTP request can be made to exhibit: the verb applied, then a retire won the
        // read-back. Serving the pre-mutation row as fresh truth would hand an operator a snapshot
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

        // the verb happened and stays audited as applied: the throw reports a failure to show the
        // result, it never un-records the act
        $this->assertCount(1, $log->applied());
    }

    #[Test]
    public function a_verb_on_an_unknown_projection_is_refused_as_not_found(): void
    {
        // only a reset may address a name with no row, since it also clears output a row never
        // recorded; every other verb has nothing to act on and says so, audited, before any write
        $store = $this->createMock(ProjectionLifecycleStore::class);
        $store->method('findRow')->willReturn(null);
        $store->expects($this->never())->method('pause');
        $log = new RecordingLog;

        try {
            $this->processor($store, $log)->process(null, $this->operation('pause'), ['name' => 'account_balance']);
            self::fail('a pause on an unknown projection must surface as ProjectionNotFound');
        } catch (ProjectionNotFound $e) {
            $this->assertSame('No projection "account_balance".', $e->getMessage());
        }

        $this->assertSame([], $log->applied());
        $this->assertSame('refused: no such projection', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function an_applied_verb_answers_the_row_it_reads_back(): void
    {
        // the answer is the read-back, never a bare 204: only a reset of a name that never ran has
        // no row to show on either side of the verb
        $store = $this->createStub(ProjectionLifecycleStore::class);
        $reads = 0;
        $store->method('findRow')->willReturnCallback(function () use (&$reads): ProjectionRow {
            return ++$reads === 1 ? $this->row() : $this->rowWithStatus(ProjectionStatus::Paused);
        });
        $store->method('pause')->willReturn(true);

        $answer = $this->processor($store, new RecordingLog)->process(null, $this->operation('pause'), ['name' => 'account_balance']);

        $this->assertInstanceOf(ProjectionResource::class, $answer);
        $this->assertSame('account_balance', $answer->name);
        $this->assertSame(ProjectionStatus::Paused->value, $answer->status);
        // no lease holder, so no liveness to ask the store about: null, never a lease read as dead
        $this->assertNull($answer->leaseLive);
    }

    /**
     * @return list<string>
     */
    private function outcomesOfAThrowingAction(ProjectionLifecycleStore $store, string $action, Throwable $expected, ?ProjectionRegistry $registry = null, ?ProjectionLane $lane = null): array
    {
        $log = new RecordingLog;
        $escaped = null;

        try {
            $this->processor($store, $log, registry: $registry, lane: $lane)->process(null, $this->operation($action), ['name' => 'account_balance']);
        } catch (Throwable $e) {
            $escaped = $e;
        }

        // the verb's own throwable escapes unchanged, after one record carrying the whole sentence:
        // a verdict without its cause says something went wrong without saying what
        $this->assertInstanceOf($expected::class, $escaped);
        $this->assertSame($expected->getMessage(), $escaped->getMessage());

        return array_map(static fn (array $r): string => (string) ($r['context']['outcome'] ?? ''), $log->records);
    }

    private function processor(ProjectionLifecycleStore $store, RecordingLog $log, bool $allowAnonymous = true, ?ProjectionRegistry $registry = null, ?ProjectionLane $lane = null): ProjectionActionProcessor
    {
        $audit = new OpsAuditLog($log);
        // the factory and the management are only reached by a mutation that applied; real
        // instances over stubs satisfy their final types
        $factory = new ProjectionResourceFactory(
            $this->createStub(ProjectionCatalog::class),
            new ProjectionWaiter($this->createStub(ProjectionCatalog::class), $this->createStub(StreamReader::class), new ProjectionRegistry, $this->createStub(DerivedStreamHead::class), $this->createStub(DerivedStreamRevision::class)),
        );
        $lane ??= new ProjectionLane($this->createStub(ProjectionStore::class), $this->createStub(Connection::class));
        $management = new ProjectionManagement($registry ?? new ProjectionRegistry, new ProjectionLanes($lane, $lane), new EventLinkWriter);

        return new ProjectionActionProcessor($store, $management, $factory, $audit, new OpsActorGate($allowAnonymous ? new OpsAuditLog(new NullLogger) : $audit, null, allowAnonymous: $allowAnonymous));
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

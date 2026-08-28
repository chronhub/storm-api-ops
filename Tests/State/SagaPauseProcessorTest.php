<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Post;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\PauseSagaInput;
use Storm\ApiOps\Resource\ResumeSagaInput;
use Storm\ApiOps\State\SagaPauseProcessor;
use Storm\ApiOps\Tests\Fixture\CollectingEvents;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Clock\PointInTime;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Event\SagaPaused;
use Storm\Saga\Event\SagaResumed;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;

use function sprintf;

final class SagaPauseProcessorTest extends TestCase
{
    #[Test]
    public function the_pause_stamps_announces_and_answers_the_fresh_snapshot(): void
    {
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('pauseInstance')
            ->with(new WorkflowId('payment', 'c-1'), 'maintenance window')
            ->willReturn(true);
        $instances->method('find')->willReturn($this->row());

        $events = new CollectingEvents;
        $log = new RecordingLog;

        $result = $this->processor($instances, $events, $log)->process(
            new PauseSagaInput(workflowType: 'payment', reason: 'maintenance window'),
            $this->operation('pause'),
            ['correlationId' => 'c-1'],
        );

        $this->assertSame('2026-08-05T10:00:00.000000+00:00', $result->pausedAt);
        $this->assertSame('maintenance window', $result->pausedReason);
        $this->assertCount(1, $events->events);
        $this->assertInstanceOf(SagaPaused::class, $events->events[0]);
        $this->assertCount(1, $log->applied());
    }

    #[Test]
    public function a_pause_with_nothing_to_freeze_is_refused_audited_and_announces_nothing(): void
    {
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('pauseInstance')->willReturn(false);

        $events = new CollectingEvents;
        $log = new RecordingLog;

        try {
            $this->processor($instances, $events, $log)->process(
                new PauseSagaInput(workflowType: 'payment'),
                $this->operation('pause'),
                ['correlationId' => 'c-gone'],
            );
            self::fail('nothing to freeze must surface as SagaPauseRefused');
        } catch (SagaPauseRefused $e) {
            $this->assertStringContainsString('absent, settled, or already paused', $e->getMessage());
        }

        $this->assertSame([], $events->events); // no phantom SagaPaused for a verb that did nothing
        $this->assertCount(0, $log->applied());
        // the refusal is AUDITED, not only unapplied: an operator reading the log must find why the
        // verb did nothing, and counting applied records alone cannot tell a refusal from a silence
        $this->assertSame('refused: nothing to freeze', $log->records[0]['context']['outcome']);
        $this->assertSame('payment/c-gone', $log->records[0]['context']['subject']);
        // one line exactly: a refusal that also fell through the failure arm would lie twice
        $this->assertCount(1, $log->records);
    }

    #[Test]
    public function an_anonymous_freeze_is_refused_before_the_store_and_audited(): void
    {
        // the gate runs BEFORE the store on purpose, so an anonymous caller learns nothing, not even
        // whether the saga exists. The store double refuses to be touched at all, which is what
        // places the guard ahead of it rather than merely somewhere on the path
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->never())->method('pauseInstance');
        $instances->expects($this->never())->method('find');

        $events = new CollectingEvents;
        $log = new RecordingLog;

        try {
            $this->processor($instances, $events, $log, allowAnonymous: false)->process(
                new PauseSagaInput(workflowType: 'payment'),
                $this->operation('pause'),
                ['correlationId' => 'c-1'],
            );
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousMutationRefused $e) {
            $this->assertStringContainsString('no authenticated actor', $e->getMessage());
        }

        $this->assertSame([], $events->events);
        $this->assertSame('refused: anonymous mutation', $log->records[0]['context']['outcome']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outage_mid_freeze_is_audited_as_a_failure_not_a_refusal(): void
    {
        // the failed: arm the projection twin carries with the same words: an infrastructure
        // outage mid-verb must leave a line the operator reads as an outage, never as their verb
        // declined, and never no line at all
        $outage = SagaStorageFailure::unavailable(new RuntimeException('the pause store went away'));
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('pauseInstance')->willThrowException($outage);

        $events = new CollectingEvents;
        $log = new RecordingLog;

        try {
            $this->processor($instances, $events, $log)->process(
                new PauseSagaInput(workflowType: 'payment'),
                $this->operation('pause'),
                ['correlationId' => 'c-1'],
            );
            self::fail('the outage must surface raw');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($outage, $e);
        }

        $this->assertSame([], $events->events); // nothing applied, so nothing announced
        $this->assertSame('failed: The saga storage failed: the pause store went away', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function the_resume_lifts_and_announces(): void
    {
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('resumeInstance')
            ->with(new WorkflowId('payment', 'c-1'))
            ->willReturn(true);
        $instances->method('find')->willReturn($this->row());

        $events = new CollectingEvents;
        $log = new RecordingLog;

        $this->processor($instances, $events, $log)->process(
            new ResumeSagaInput(workflowType: 'payment'),
            $this->operation('resume'),
            ['correlationId' => 'c-1'],
        );

        $this->assertCount(1, $events->events);
        $this->assertInstanceOf(SagaResumed::class, $events->events[0]);
        $this->assertCount(1, $log->applied());
    }

    #[Test]
    public function a_resume_with_nothing_to_lift_is_refused(): void
    {
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeInstance')->willReturn(false);

        $log = new RecordingLog;

        try {
            $this->processor($instances, new CollectingEvents, $log)->process(
                new ResumeSagaInput(workflowType: 'payment'),
                $this->operation('resume'),
                ['correlationId' => 'c-1'],
            );
            self::fail('nothing to lift must surface as SagaPauseRefused');
        } catch (SagaPauseRefused $e) {
            $this->assertStringContainsString('absent or was not paused', $e->getMessage());
        }

        // audited for the same reason as its pause twin: document the refusal, not just the no-op
        $this->assertSame('refused: nothing to lift', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function the_announced_generation_is_the_row_s_own_and_falls_back_to_zero_when_it_vanished(): void
    {
        // the generation separates two runs of one correlation, so an off-by-one on the announcement
        // sends a listener to the wrong run. Both arms are pinned: the row's value when it is there,
        // and the fallback when the row disappeared between the stamp and the read-back, where a
        // fabricated number would be worse than an obviously-absent zero
        $present = $this->createStub(WorkflowInstanceStore::class);
        $present->method('pauseInstance')->willReturn(true);
        $present->method('find')->willReturn($this->row());

        $events = new CollectingEvents;
        $this->processor($present, $events, new RecordingLog)->process(
            new PauseSagaInput(workflowType: 'payment'),
            $this->operation('pause'),
            ['correlationId' => 'c-1'],
        );

        $this->assertInstanceOf(SagaPaused::class, $events->events[0]);
        $this->assertSame(1, $events->events[0]->generation);

        $liftedPresent = new CollectingEvents;
        $resumable = $this->createStub(WorkflowInstanceStore::class);
        $resumable->method('resumeInstance')->willReturn(true);
        $resumable->method('find')->willReturn($this->row());
        $this->processor($resumable, $liftedPresent, new RecordingLog)->process(
            new ResumeSagaInput(workflowType: 'payment'),
            $this->operation('resume'),
            ['correlationId' => 'c-1'],
        );

        $this->assertInstanceOf(SagaResumed::class, $liftedPresent->events[0]);
        $this->assertSame(1, $liftedPresent->events[0]->generation);

        // both verbs again with the row GONE: the fallback is a literal, so only an absent row can
        // pin it, and each verb carries its own
        $vanished = $this->createStub(WorkflowInstanceStore::class);
        $vanished->method('pauseInstance')->willReturn(true);
        $vanished->method('resumeInstance')->willReturn(true);
        $vanished->method('find')->willReturn(null);

        $frozenGone = new CollectingEvents;
        $this->processor($vanished, $frozenGone, new RecordingLog)->process(
            new PauseSagaInput(workflowType: 'payment'),
            $this->operation('pause'),
            ['correlationId' => 'c-1'],
        );
        $this->assertInstanceOf(SagaPaused::class, $frozenGone->events[0]);
        $this->assertSame(0, $frozenGone->events[0]->generation);

        $liftedGone = new CollectingEvents;
        $this->processor($vanished, $liftedGone, new RecordingLog)->process(
            new ResumeSagaInput(workflowType: 'payment'),
            $this->operation('resume'),
            ['correlationId' => 'c-1'],
        );
        $this->assertInstanceOf(SagaResumed::class, $liftedGone->events[0]);
        $this->assertSame(0, $liftedGone->events[0]->generation);
    }

    #[Test]
    public function a_foreign_input_class_is_a_wiring_fault_before_the_gate(): void
    {
        // the input guard sits before the actor gate and before any store read: a resource
        // declaring another input class is a composition bug, refused with nothing touched
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->never())->method('pauseInstance');
        $instances->expects($this->never())->method('resumeInstance');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/declare their input class/');

        $this->processor($instances, new CollectingEvents, new RecordingLog)->process(
            new stdClass,
            $this->operation('pause'),
            ['correlationId' => 'c-1'],
        );
    }

    #[Test]
    public function an_unknown_ops_action_is_a_wiring_fault_and_mutates_nothing(): void
    {
        // the default arm guards the resource declaration, never a caller's input: the fault names
        // the stray action, and neither verb, audit nor announcement ran
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->never())->method('pauseInstance');
        $instances->expects($this->never())->method('resumeInstance');
        $events = new CollectingEvents;
        $log = new RecordingLog;

        try {
            $this->processor($instances, $events, $log)->process(
                new ResumeSagaInput(workflowType: 'payment'),
                $this->operation('unpause'),
                ['correlationId' => 'c-1'],
            );
            $this->fail('an undeclared ops action must be refused as a wiring fault');
        } catch (LogicException $e) {
            $this->assertStringContainsString('"unpause"', $e->getMessage());
            $this->assertStringContainsString('not a missing saga', $e->getMessage());
        }

        $this->assertSame([], $events->events);
        // no line at ALL, not merely none applied: a wiring fault mutates nothing, so a failed:
        // line here would blame an outage for a declaration bug
        $this->assertSame([], $log->records);
    }

    #[Test]
    public function a_freeze_whose_instance_is_pruned_before_the_read_back_names_the_verb_that_applied(): void
    {
        // neither verb deletes, so an empty read-back can only be a retention prune racing it. The
        // verb is interpolated into the sentence, and an operator chasing the incident goes after
        // the one it names: a lifted freeze reported as a pause sends them to the wrong minute
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('pauseInstance')->willReturn(true);
        $instances->method('resumeInstance')->willReturn(true);
        $instances->method('find')->willReturn($this->row());

        foreach ([
            'pause' => new PauseSagaInput(workflowType: 'payment'),
            'resume' => new ResumeSagaInput(workflowType: 'payment'),
        ] as $verb => $input) {
            $log = new RecordingLog;

            try {
                $this->processor($instances, new CollectingEvents, $log, pruned: true)->process(
                    $input,
                    $this->operation($verb),
                    ['correlationId' => 'c-1'],
                );
                self::fail('a vanished read-back must surface as SagaCommandNotFound');
            } catch (SagaCommandNotFound $e) {
                $this->assertSame(sprintf(
                    'Saga c-1 vanished between the %s and reading it back; the %s applied, but the instance is gone.',
                    $verb,
                    $verb,
                ), $e->getMessage());
            }

            // the trail still reads applied, because it was: the 404 is about the read-back alone
            $this->assertCount(1, $log->applied());
        }
    }

    // rig

    private function processor(WorkflowInstanceStore $instances, EventDispatcherInterface $events, RecordingLog $log, bool $allowAnonymous = true, bool $pruned = false): SagaPauseProcessor
    {
        $audit = new OpsAuditLog($log);

        // the gateway is final; its seam is the connection. The transactional read serves the
        // fresh snapshot the processor answers, or the empty result a retention prune leaves
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturn($pruned ? [] : [$this->snapshot()]);

        return new SagaPauseProcessor(
            $instances,
            $instances,
            new SagaInspectionGateway($connection, new WorkflowRegistry),
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: $allowAnonymous),
            $events,
        );
    }

    private function operation(string $action): Post
    {
        return new Post(extraProperties: ['storm_ops_action' => $action]);
    }

    private function row(): WorkflowInstanceRow
    {
        return WorkflowInstanceRow::fresh(
            new WorkflowId('payment', 'c-1'),
            'await',
            [],
            [],
            PointInTime::fromStorage('2026-08-05T10:00:00.000000+00:00'),
            1,
            1,
        );
    }

    private function snapshot(): SagaSnapshot
    {
        return new SagaSnapshot(
            workflowType: 'payment',
            stateKey: 'await',
            status: 'running',
            version: 3,
            startedAt: '2026-08-05T09:00:00.000000+00:00',
            updatedAt: null,
            generation: 1,
            definitionVersion: 1,
            retryTotal: 0,
            waivedAt: null,
            retries: [],
            compensations: [],
            timers: [],
            outbox: [],
            pausedAt: '2026-08-05T10:00:00.000000+00:00',
            pausedReason: 'maintenance window',
        );
    }
}

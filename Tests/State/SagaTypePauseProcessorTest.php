<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Post;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\Error\UnknownWorkflowType;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\PauseSagaTypeInput;
use Storm\ApiOps\State\SagaTypePauseProcessor;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class SagaTypePauseProcessorTest extends TestCase
{
    #[Test]
    public function the_type_pause_registers_and_answers_the_registry_truth(): void
    {
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('pauseType')->with('payment', 'broken handler');
        $instances->method('pausedType')->willReturn(true);

        $log = new RecordingLog;

        $result = $this->processor($instances, $log)->process(
            new PauseSagaTypeInput(reason: 'broken handler'),
            $this->operation('pause'),
            ['workflowType' => 'payment'],
        );

        $this->assertSame('payment', $result->workflowType);
        $this->assertTrue($result->paused); // read back from the registry, never assumed
        $this->assertCount(1, $log->applied());
    }

    #[Test]
    public function the_type_resume_deletes_the_registration(): void
    {
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('resumeType')->with('payment')->willReturn(true);
        $instances->method('pausedType')->willReturn(false);

        $result = $this->processor($instances, new RecordingLog)->process(
            null,
            $this->operation('resume'),
            ['workflowType' => 'payment'],
        );

        $this->assertFalse($result->paused);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outage_mid_type_freeze_is_audited_as_a_failure_not_a_refusal(): void
    {
        // the failed: arm the projection twin carries with the same words: an infrastructure
        // outage mid-verb must leave a line the operator reads as an outage, never as their verb
        // declined, and never no line at all
        $outage = SagaStorageFailure::unavailable(new RuntimeException('the pause store went away'));
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('pauseType')->willThrowException($outage);

        $log = new RecordingLog;

        try {
            $this->processor($instances, $log)->process(
                new PauseSagaTypeInput,
                $this->operation('pause'),
                ['workflowType' => 'payment'],
            );
            self::fail('the outage must surface raw');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($outage, $e);
        }

        $this->assertSame('failed: The saga storage failed: the pause store went away', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function resuming_an_unfrozen_type_is_refused(): void
    {
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeType')->willReturn(false);

        $log = new RecordingLog;

        try {
            $this->processor($instances, $log)->process(
                null,
                $this->operation('resume'),
                ['workflowType' => 'payment'],
            );
            self::fail('lifting a type that was never frozen must surface as SagaPauseRefused');
        } catch (SagaPauseRefused $e) {
            $this->assertStringContainsString('was not frozen', $e->getMessage());
        }

        // the refusal is audited: an operator must read WHY the lift did nothing, and counting
        // applied records cannot tell a refusal from a silence
        $this->assertSame('refused: type not frozen', $log->records[0]['context']['outcome']);
        // one line exactly: a refusal that also fell through the failure arm would lie twice
        $this->assertCount(1, $log->records);
    }

    #[Test]
    public function an_unknown_ops_action_is_a_wiring_fault_and_mutates_nothing(): void
    {
        // the default arm guards the resource declaration, never a caller's input: the fault is a
        // LogicException naming the stray action, and neither verb nor audit ran
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->never())->method('pauseType');
        $instances->expects($this->never())->method('resumeType');
        $log = new RecordingLog;

        try {
            $this->processor($instances, $log)->process(null, $this->operation('freeze'), ['workflowType' => 'payment']);
            $this->fail('an undeclared ops action must be refused as a wiring fault');
        } catch (LogicException $e) {
            $this->assertStringContainsString('"freeze"', $e->getMessage());
            $this->assertStringContainsString('resource declaration fault', $e->getMessage());
        }

        // no line at ALL, not merely none applied: a wiring fault mutates nothing, so a failed:
        // line here would blame an outage for a declaration bug
        $this->assertSame([], $log->records);
    }

    #[Test]
    public function an_anonymous_type_freeze_is_refused_before_the_registry(): void
    {
        // the widest verb of the surface, every instance of a type at once, so the gate runs before
        // the registry is touched and an unnamed caller cannot even learn the type exists
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->never())->method('pauseType');

        $log = new RecordingLog;

        try {
            $this->processor($instances, $log, allowAnonymous: false)->process(
                new PauseSagaTypeInput(reason: 'anonymous'),
                $this->operation('pause'),
                ['workflowType' => 'payment'],
            );
            self::fail('an unnamed caller must not reach the registry');
        } catch (AnonymousMutationRefused $e) {
            $this->assertStringContainsString('no authenticated actor', $e->getMessage());
        }

        $this->assertSame([], $log->applied());
        $this->assertSame('refused: anonymous mutation', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function freezing_a_type_no_registry_knows_is_refused_and_writes_nothing(): void
    {
        // the store stamps whatever string it is handed, so an unchecked type would answer 200 with
        // `paused: true` while every instance of the type the operator meant keeps running
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->never())->method('pauseType');
        $log = new RecordingLog;

        try {
            $this->processor($instances, $log)->process(
                new PauseSagaTypeInput(reason: 'incident 4213'),
                $this->operation('pause'),
                ['workflowType' => 'cor-9f1c4e2a'], // a correlation id, the shape of the mistake
            );
            self::fail('freezing an unregistered type must surface as UnknownWorkflowType');
        } catch (UnknownWorkflowType $e) {
            $this->assertStringContainsString('cor-9f1c4e2a', $e->getMessage());
        }

        $this->assertSame([], $log->applied());
        $this->assertSame('refused: unknown workflow type', $log->records[0]['context']['outcome']);
        // one line exactly: a refusal that also fell through the failure arm would lie twice
        $this->assertCount(1, $log->records);
    }

    #[Test]
    public function lifting_a_type_no_registry_knows_stays_the_conflict_it_already_was(): void
    {
        // the registry check is on the FREEZE alone, on purpose: a resume writes nothing, and the
        // store already answers false on a type it never froze, so the 409 says the truer thing
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeType')->willReturn(false);

        $this->expectException(SagaPauseRefused::class);

        $this->processor($instances, new RecordingLog)->process(
            null,
            $this->operation('resume'),
            ['workflowType' => 'cor-9f1c4e2a'],
        );
    }

    // rig

    private function processor(WorkflowInstanceStore $instances, RecordingLog $log, bool $allowAnonymous = true, ?WorkflowRegistry $registry = null): SagaTypePauseProcessor
    {
        $audit = new OpsAuditLog($log);

        return new SagaTypePauseProcessor(
            $instances,
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: $allowAnonymous),
            $registry ?? self::registry('payment'),
        );
    }

    /**
     * A registry holding exactly the named types, so a freeze can be judged against a real one.
     *
     * @param  non-empty-string  ...$types
     */
    private static function registry(string ...$types): WorkflowRegistry
    {
        return new WorkflowRegistry(array_map(
            static fn (string $name): WorkflowDefinition => new WorkflowDefinition($name, ['done' => new FinalState('done')], 'done'),
            $types,
        ));
    }

    private function operation(string $action): Post
    {
        return new Post(extraProperties: ['storm_ops_action' => $action]);
    }
}

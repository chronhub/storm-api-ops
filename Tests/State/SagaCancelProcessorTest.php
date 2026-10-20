<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Post;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaCancelRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\CancelSagaInput;
use Storm\ApiOps\State\SagaCancelProcessor;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Testing\InMemory\PlainMessageTypeMapper;
use Throwable;

final class SagaCancelProcessorTest extends TestCase
{
    #[Test]
    public function a_refused_cancel_audits_the_exact_sentence_it_answers(): void
    {
        // the trail must not tell a story of its own: one record, carrying the response sentence
        $log = new RecordingLog;

        try {
            $this->processor($log, [$this->snapshot('running')])->process(
                new CancelSagaInput(workflowType: 'payment'),
                new Post,
                ['correlationId' => 'c-1'],
            );
            self::fail('a falsy engine cancel must surface as SagaCancelRefused');
        } catch (SagaCancelRefused $e) {
            $this->assertStringContainsString('effect-gating wait or held by a competing step', $e->getMessage());

            $this->assertCount(1, $log->records);
            $this->assertSame('cancel', $log->records[0]['context']['action']);
            $this->assertSame('refused: '.$e->getMessage(), $log->records[0]['context']['outcome']);
        }
    }

    #[Test]
    public function a_forced_refusal_on_a_running_saga_blames_the_competing_step_not_the_wait(): void
    {
        // force disarms the gating wait, so a running instance can only have been held by a
        // competing step; the answer must not mention the wait nor advise force again
        try {
            $this->processor(new RecordingLog, [$this->snapshot('running')])->process(
                new CancelSagaInput(workflowType: 'payment', force: true),
                new Post,
                ['correlationId' => 'c-1'],
            );
            self::fail('a falsy forced cancel must surface as SagaCancelRefused');
        } catch (SagaCancelRefused $e) {
            $this->assertStringContainsString('despite force', $e->getMessage());
            $this->assertStringContainsString('competing step', $e->getMessage());
            $this->assertStringNotContainsString('effect-gating wait', $e->getMessage());
            $this->assertStringNotContainsString('"force": true', $e->getMessage());
        }
    }

    #[Test]
    public function a_refusal_over_a_vanished_instance_says_nothing_to_cancel(): void
    {
        $this->expectException(SagaCancelRefused::class);
        $this->expectExceptionMessageMatches('/no saga instance carries this correlation/');

        $this->processor(new RecordingLog, [])->process(
            new CancelSagaInput(workflowType: 'payment'),
            new Post,
            ['correlationId' => 'c-gone'],
        );
    }

    #[Test]
    public function a_refusal_over_a_settled_run_names_the_status_it_settled_as(): void
    {
        $this->expectException(SagaCancelRefused::class);
        $this->expectExceptionMessageMatches('/already settled as "compensated"/');

        $this->processor(new RecordingLog, [$this->snapshot('compensated')])->process(
            new CancelSagaInput(workflowType: 'payment'),
            new Post,
            ['correlationId' => 'c-1'],
        );
    }

    #[Test]
    public function a_cancel_whose_instance_is_pruned_before_the_read_back_answers_both_facts(): void
    {
        // cancel halts, never deletes, so an empty read-back can only be a retention prune racing
        // the verb. Both facts have to travel: the cancel applied, and there is nothing left to
        // show for it. Reading the absent snapshot instead would be a TypeError dressed as a 500
        // over a saga that behaved
        $log = new RecordingLog;

        try {
            $this->processor($log, [], cancelled: true)->process(
                new CancelSagaInput(workflowType: 'payment'),
                new Post,
                ['correlationId' => 'c-1'],
            );
            self::fail('a vanished read-back must surface as SagaCommandNotFound');
        } catch (SagaCommandNotFound $e) {
            $this->assertSame(
                'Saga c-1 vanished between the cancel and reading it back; the cancel applied, but the instance is gone.',
                $e->getMessage(),
            );
        }

        // the trail still reads applied, because it was: the 404 is about the read-back alone, and
        // an audit calling it refused would blame the operator's verb for a prune's timing
        $this->assertCount(1, $log->applied());
    }

    #[Test]
    public function an_anonymous_cancel_is_refused_before_the_engine(): void
    {
        // the gate stands ahead of the engine: a cancel that never names its caller must not reach
        // the saga at all, and the audit carries the refusal rather than a silence
        $log = new RecordingLog;

        try {
            $this->processor($log, [$this->snapshot('running')], allowAnonymous: false)->process(
                new CancelSagaInput(workflowType: 'payment'),
                new Post,
                ['correlationId' => 'c-1'],
            );
            self::fail('an unnamed caller must not reach the engine');
        } catch (AnonymousMutationRefused $e) {
            $this->assertStringContainsString('no authenticated actor', $e->getMessage());
        }

        $this->assertSame([], $log->applied());
        $this->assertSame('refused: anonymous mutation', $log->records[0]['context']['outcome']);

        // the subject is what the trail is for: an operator reading it must know which saga was
        // refused, so the type and the correlation both have to be in it, in that order
        $this->assertSame('payment/c-1', $log->records[0]['context']['subject']);
    }

    #[Test]
    public function a_foreign_input_class_is_a_wiring_fault_refused_ahead_of_the_gate(): void
    {
        // a resource declaring another input class is a composition bug, and it is caught before
        // the actor gate and before the engine. The order is what this pins, so the caller is anonymous
        // under a gate that refuses: whichever guard runs first names itself in the exception, and
        // an AnonymousMutationRefused here would mean an operator's 403 hides a wiring fault
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('cancel');
        $log = new RecordingLog;
        $audit = new OpsAuditLog($log);

        $processor = new SagaCancelProcessor(
            $engine,
            new SagaInspectionGateway($this->createStub(Connection::class), new WorkflowRegistry, new PlainMessageTypeMapper),
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: false),
        );

        try {
            $processor->process(new stdClass, new Post, ['correlationId' => 'c-1']);
            self::fail('a foreign input class must be refused as a wiring fault');
        } catch (LogicException $e) {
            $this->assertStringContainsString('declares its input class', $e->getMessage());
        }

        // the gate records every refusal it pronounces, so its silence is what proves it never ran
        $this->assertSame([], $log->records);
    }

    #[Test]
    public function a_storage_failure_out_of_the_engine_is_audited_as_failed(): void
    {
        // a saga storage outage is a failure: an audit line calling it refused would read as an
        // operator's verb declined when the store simply went away
        $outage = SagaStorageFailure::unavailable(new RuntimeException('pg down'));

        $this->assertSame("failed: Storm\\Saga\\Exception\\SagaStorageFailure: The saga storage failed.\ncaused by: RuntimeException: pg down", $this->outcomeOfAThrowingCancel($outage));
    }

    #[Test]
    public function a_compensation_failure_is_audited_as_failed(): void
    {
        // the engine lets a compensation's own failure escape the cancel: the verb was accepted and
        // did not complete, and no guard declined it
        $failure = new RuntimeException('compensation command could not be issued');

        $this->assertSame('failed: RuntimeException: compensation command could not be issued', $this->outcomeOfAThrowingCancel($failure));
    }

    #[Test]
    public function a_purged_definition_version_is_audited_as_failed(): void
    {
        // the engine raises this `LogicException` about the instance's pinned definition, and the
        // resource declaration is sound: the cancel was attempted and failed
        $purged = WorkflowVersionNotFound::for('payment', 2);

        $this->assertSame('failed: '.WorkflowVersionNotFound::class.': '.$purged->getMessage(), $this->outcomeOfAThrowingCancel($purged));
    }

    #[Test]
    public function a_read_that_fails_while_naming_the_refusal_is_audited_as_failed(): void
    {
        // the engine declined and the read that names why went away: no cause reached the caller,
        // so the line reads as a failure and the read's own throwable escapes unchanged
        $engine = $this->createStub(SagaEngine::class);
        $engine->method('cancel')->willReturn(false);
        $outage = new RuntimeException('pg down');
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willThrowException($outage);
        $log = new RecordingLog;

        $processor = new SagaCancelProcessor(
            $engine,
            new SagaInspectionGateway($connection, new WorkflowRegistry, new PlainMessageTypeMapper),
            new OpsAuditLog($log),
            new OpsActorGate(new OpsAuditLog(new NullLogger), null, allowAnonymous: true),
        );

        try {
            $processor->process(new CancelSagaInput(workflowType: 'payment'), new Post, ['correlationId' => 'c-1']);
            self::fail('a read that fails must escape');
        } catch (RuntimeException $e) {
            $this->assertSame($outage, $e);
        }

        $this->assertCount(1, $log->records);
        $this->assertSame('failed: RuntimeException: pg down', $log->records[0]['context']['outcome'] ?? null);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function namedDeclines(): iterable
    {
        yield 'unregistered workflow, a 404' => [WorkflowNotFound::named('payment')];
        yield 'stale instance, a 409' => [StaleWorkflowInstance::forStep('payment', 'c-1', 3)];
    }

    #[Test]
    #[DataProvider('namedDeclines')]
    public function a_named_decline_out_of_the_engine_is_audited_as_refused(Throwable $decline): void
    {
        // the engine's declines that the resource answers with a 4xx stay refusals
        $this->assertSame('refused: '.$decline->getMessage(), $this->outcomeOfAThrowingCancel($decline));
    }

    // rig

    private function outcomeOfAThrowingCancel(Throwable $thrown): string
    {
        $engine = $this->createStub(SagaEngine::class);
        $engine->method('cancel')->willThrowException($thrown);
        $log = new RecordingLog;

        $processor = new SagaCancelProcessor(
            $engine,
            new SagaInspectionGateway($this->createStub(Connection::class), new WorkflowRegistry, new PlainMessageTypeMapper),
            new OpsAuditLog($log),
            new OpsActorGate(new OpsAuditLog(new NullLogger), null, allowAnonymous: true),
        );

        $escaped = null;

        try {
            $processor->process(new CancelSagaInput(workflowType: 'payment'), new Post, ['correlationId' => 'c-1']);
        } catch (Throwable $e) {
            $escaped = $e;
        }

        // the engine's throwable escapes unchanged, after exactly one record carrying the whole
        // sentence: a verdict without its cause says something went wrong without saying what
        $this->assertSame($thrown, $escaped);
        $this->assertCount(1, $log->records);
        $outcome = $log->records[0]['context']['outcome'] ?? null;
        $this->assertIsString($outcome);

        return $outcome;
    }

    /**
     * @param  list<SagaSnapshot>  $snapshots
     */
    private function processor(RecordingLog $log, array $snapshots, bool $allowAnonymous = true, bool $cancelled = false): SagaCancelProcessor
    {
        $engine = $this->createStub(SagaEngine::class);
        $engine->method('cancel')->willReturn($cancelled);

        $audit = new OpsAuditLog($log);

        // the gateway is final; its seam is the connection. The transactional read serves the
        // post-refusal snapshot the processor discriminates on
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturn($snapshots);

        return new SagaCancelProcessor(
            $engine,
            new SagaInspectionGateway($connection, new WorkflowRegistry, new PlainMessageTypeMapper),
            $audit,
            new OpsActorGate($allowAnonymous ? new OpsAuditLog(new NullLogger) : $audit, null, allowAnonymous: $allowAnonymous),
        );
    }

    private function snapshot(string $status): SagaSnapshot
    {
        return new SagaSnapshot(
            workflowType: 'payment',
            stateKey: 'await',
            status: $status,
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
        );
    }
}

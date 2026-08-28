<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Post;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\RedriveSagaInput;
use Storm\ApiOps\State\SagaRedriveProcessor;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Stringable;

final class SagaRedriveProcessorTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_saga_pruned_between_the_redrive_and_the_read_back_is_reported_not_dressed_up(): void
    {
        // the race no HTTP request can be made to exhibit: the store guards proved the saga was
        // running when the row flipped, then a concurrent prune removed the instance before the
        // read-back. The honest answer is a 404 naming the vanishing, never a fabricated snapshot,
        // and never a silent success with nothing to show.
        $outbox = $this->createMock(WorkflowOutboxWriter::class);
        $outbox->expects($this->once())->method('redrive')
            ->with('c-vanished', 'mid-1', false)
            ->willReturn(RedriveOutcome::Redriven);

        // the gateway is final; its seam is the connection. An empty transactional read IS the
        // vanished instance, no closure ever touching a database
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturn([]);

        $log = new class() extends AbstractLogger
        {
            /** @var list<array{message: string, context: array<array-key, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $audit = new OpsAuditLog($log);

        $processor = new SagaRedriveProcessor(
            $outbox,
            new SagaInspectionGateway($connection, new WorkflowRegistry),
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: true),
        );

        try {
            $processor->process(new RedriveSagaInput(messageId: 'mid-1'), new Post, ['correlationId' => 'c-vanished']);
            self::fail('the vanished instance must surface as SagaCommandNotFound');
        } catch (SagaCommandNotFound $e) {
            self::assertStringContainsString('vanished between the redrive and reading it back', $e->getMessage());
            self::assertStringContainsString('c-vanished', $e->getMessage());
        }

        // the repair itself HAPPENED and stays audited as applied: the throw reports a failure to
        // show the result, never un-records the act
        $applied = array_filter($log->records, static fn (array $r): bool => ($r['context']['outcome'] ?? null) === 'applied');
        self::assertCount(1, $applied);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outage_mid_redrive_is_audited_as_a_failure_not_a_guards_decline(): void
    {
        // the failed: arm the projection twin carries with the same words: an outbox outage
        // mid-verb must leave a line the operator reads as an outage, never as a guard's decline,
        // and never no line at all
        $outage = SagaStorageFailure::unavailable(new RuntimeException('the outbox went away'));
        $outbox = $this->createStub(WorkflowOutboxWriter::class);
        $outbox->method('redrive')->willThrowException($outage);

        $log = new RecordingLog;
        $audit = new OpsAuditLog($log);

        $processor = new SagaRedriveProcessor(
            $outbox,
            new SagaInspectionGateway($this->createStub(Connection::class), new WorkflowRegistry),
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: true),
        );

        try {
            $processor->process(new RedriveSagaInput(messageId: 'mid-1'), new Post, ['correlationId' => 'c-1']);
            self::fail('the outage must surface raw');
        } catch (SagaStorageFailure $e) {
            self::assertSame($outage, $e);
        }

        self::assertSame('failed: The saga storage failed: the outbox went away', $log->records[0]['context']['outcome']);
    }

    #[Test]
    public function an_anonymous_redrive_is_refused_before_the_outbox(): void
    {
        // a redrive puts a command back in flight, so it names who asked before anything moves. The
        // outbox double refuses to be touched, which is what places the gate ahead of the repair
        // rather than merely somewhere along it
        $outbox = $this->createMock(WorkflowOutboxWriter::class);
        $outbox->expects($this->never())->method('redrive');

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturn([]);

        $log = new RecordingLog;
        $audit = new OpsAuditLog($log);

        $processor = new SagaRedriveProcessor(
            $outbox,
            new SagaInspectionGateway($connection, new WorkflowRegistry),
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: false),
        );

        try {
            $processor->process(new RedriveSagaInput(messageId: 'mid-1'), new Post, ['correlationId' => 'c-1']);
            self::fail('an unnamed caller must not reach the outbox');
        } catch (AnonymousMutationRefused $e) {
            self::assertStringContainsString('no authenticated actor', $e->getMessage());
        }

        self::assertSame('refused: anonymous mutation', $log->records[0]['context']['outcome']);

        // a redrive names a single dead command, so the trail must carry BOTH halves of its address:
        // the correlation that scopes it and the message that identifies it inside that correlation
        self::assertSame('c-1/mid-1', $log->records[0]['context']['subject']);
    }

    #[Test]
    public function a_foreign_input_class_is_a_wiring_fault_refused_ahead_of_the_gate(): void
    {
        // the same wiring as the test above, differing only by the input class, so the exception
        // TYPE says which guard ran first: a resource declaring another input is a composition bug,
        // and it must be caught before the actor gate and before the outbox. An
        // AnonymousMutationRefused here would mean a 403 hides a wiring fault from whoever wired it
        $outbox = $this->createMock(WorkflowOutboxWriter::class);
        $outbox->expects($this->never())->method('redrive');

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturn([]);

        $log = new RecordingLog;
        $audit = new OpsAuditLog($log);

        $processor = new SagaRedriveProcessor(
            $outbox,
            new SagaInspectionGateway($connection, new WorkflowRegistry),
            $audit,
            new OpsActorGate($audit, null, allowAnonymous: false),
        );

        try {
            $processor->process(new stdClass, new Post, ['correlationId' => 'c-1']);
            self::fail('a foreign input class must be refused as a wiring fault');
        } catch (LogicException $e) {
            self::assertStringContainsString('declares its input class', $e->getMessage());
        }

        // the gate records every refusal it pronounces, so its silence is what proves it never ran
        self::assertSame([], $log->records);
    }
}

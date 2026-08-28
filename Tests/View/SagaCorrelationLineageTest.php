<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\View\SagaCorrelationLineage;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;

/**
 * The adapter that keeps the coordination module's record on its own side of the seam.
 */
final class SagaCorrelationLineageTest extends TestCase
{
    #[Test]
    public function a_correlation_with_no_saga_has_no_children(): void
    {
        self::assertSame([], $this->lineage([])->childrenOf('corr-9'));
    }

    #[Test]
    #[Group('adversarial')]
    public function only_the_child_id_s_cross_the_seam(): void
    {
        // the whole reason this adapter exists: a screen walking `$snapshot->children` would be
        // coupled to the coordination module's record for one string per row
        $children = $this->lineage([[
            'workflow_type' => 'settlement_leg', 'correlation_id' => 'corr-child', 'status' => 'done',
        ]])->childrenOf('corr-9');

        self::assertSame(['corr-child'], $children);
    }

    #[Test]
    #[Group('adversarial')]
    public function every_child_crosses_and_not_only_the_first(): void
    {
        // a fan-out is the case this exists for, and a fixture with ONE child cannot tell a whole
        // list from a truncated one: the widening would silently lose every sibling but the first
        $children = $this->lineage([
            ['workflow_type' => 'settlement_leg', 'correlation_id' => 'corr-a', 'status' => 'done'],
            ['workflow_type' => 'settlement_leg', 'correlation_id' => 'corr-b', 'status' => 'running'],
        ])->childrenOf('corr-9');

        self::assertSame(['corr-a', 'corr-b'], $children);
    }

    /**
     * @param  list<array<string, mixed>>  $childRows
     */
    private function lineage(array $childRows): SagaCorrelationLineage
    {
        $instance = [[
            'workflow_type' => 'transfer', 'state_key' => 'await_legs', 'status' => 'running', 'version' => 3,
            'started_at' => null, 'updated_at' => null, 'waived_at' => null, 'generation' => 1,
            'definition_version' => 1, 'retry_total' => 0, 'retries' => null, 'compensations' => null,
            'parent_workflow_type' => null, 'parent_correlation_id' => null, 'root_correlation_id' => null,
            'state_version' => 1, 'vars' => null, 'retimes' => 0,
            'paused_at' => null, 'type_paused' => false, 'paused_reason' => null,
        ]];

        // the gateway pays 1+2N reads: the instances, then the timers, outbox and children of each.
        // The children are the LAST of the three, which is what this sequence answers.
        $call = 0;
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($connection));
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($instance, $childRows, &$call): array {
                $call++;

                return match (true) {
                    $call === 1 => $instance,
                    str_contains($sql, 'parent_correlation_id') => $childRows,
                    default => [],
                };
            },
        );

        return new SagaCorrelationLineage(new SagaInspectionGateway($connection, new WorkflowRegistry));
    }
}

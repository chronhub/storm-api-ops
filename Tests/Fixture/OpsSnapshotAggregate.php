<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Storm\Aggregate\AggregateRootBehavior;
use Storm\Aggregate\SnapshotBehavior;
use Storm\Contracts\Aggregate\SnapshotableAggregateRoot;

/**
 * A snapshotable aggregate, which is the opt-in the ops read branches on: this one exposes state
 * and is therefore reconstituted, where a plain root answers existence and version alone.
 *
 * It records nothing. The reads under test reach it through a snapshot and an empty tail, so the
 * behavior that matters here is restoring state and reporting a version, never a replay.
 *
 * @implements SnapshotableAggregateRoot<OpsAggregateId>
 */
final class OpsSnapshotAggregate implements SnapshotableAggregateRoot
{
    /** @use AggregateRootBehavior<OpsAggregateId> */
    use AggregateRootBehavior;

    use SnapshotBehavior;

    private string $label = '';

    public function toSnapshot(): array
    {
        return [
            self::SNAPSHOT_VERSION_KEY => self::currentSnapshotVersion(),
            'label' => $this->label,
        ];
    }

    protected function restoreState(array $state): void
    {
        $this->label = (string) $state['label'];
    }
}

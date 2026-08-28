<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use RuntimeException;
use Storm\Telemetry\Metrics\MetricsCollector;

/**
 * A collector whose tables are gone under it, the shape an ops read must name rather than serve as
 * an empty block.
 */
final readonly class ThrowingCollector implements MetricsCollector
{
    public function families(): array
    {
        throw new RuntimeException('the table is gone');
    }
}

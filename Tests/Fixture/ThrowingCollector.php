<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use RuntimeException;
use Storm\Telemetry\Metrics\MetricsCollector;
use Throwable;

/**
 * A collector whose tables are gone under it, the shape an ops read must name rather than serve as
 * an empty block.
 */
final readonly class ThrowingCollector implements MetricsCollector
{
    public function __construct(
        private ?Throwable $failure = null,
    ) {}

    public function families(): array
    {
        throw $this->failure ?? new RuntimeException('the table is gone');
    }
}

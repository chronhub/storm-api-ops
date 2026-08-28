<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use RuntimeException;
use Storm\Telemetry\Metrics\MetricsCollector;

/**
 * A second failing collector, so a degraded read can be shown to name more than the first one it met.
 */
final readonly class OtherThrowingCollector implements MetricsCollector
{
    public function families(): array
    {
        throw new RuntimeException('this one is gone too');
    }
}

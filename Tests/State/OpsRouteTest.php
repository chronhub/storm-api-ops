<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\State\OpsRoute;

final class OpsRouteTest extends TestCase
{
    #[Test]
    public function a_present_route_value_is_read_as_supplied(): void
    {
        $this->assertSame('c-1', OpsRoute::string(['correlationId' => 'c-1'], 'correlationId', '*'));
    }

    #[Test]
    public function an_absent_route_value_reads_as_the_default(): void
    {
        // a collection operation carries no correlation, and the read gate names its subject `*`
        $this->assertSame('*', OpsRoute::string([], 'correlationId', '*'));
        $this->assertSame('', OpsRoute::string(['category' => 'account'], 'correlationId'));

        // an operation declaring no extra property hands over null rather than an empty array
        $this->assertSame('', OpsRoute::string(null, 'storm_ops_action'));
    }

    #[Test]
    public function route_values_are_cast_by_ops_route_alone(): void
    {
        // every State class reads a URI variable or an extra property through `OpsRoute`, so the one
        // cast that serves the analyzer, and its ignore directive, are written once
        $state = dirname(__DIR__, 2).'/State';
        $scanned = $casts = [];

        foreach (glob($state.'/*.php') ?: [] as $path) {
            $file = basename($path);
            if ($file === 'OpsRoute.php') {
                continue;
            }

            $scanned[] = $file;
            foreach (file($path) ?: [] as $index => $line) {
                if (preg_match('/\(string\)\s*\(?\s*\$(?:uriVariables\[|operation->getExtraProperties\(\)\[)/', $line) === 1) {
                    $casts[] = $file.':'.($index + 1);
                }
            }
        }

        // the scan reaches a processor that reads both kinds of value, so an empty result means the
        // State classes were read and hold no inline cast
        $this->assertContains('SagaPauseProcessor.php', $scanned);
        $this->assertSame([], $casts, 'Read the route value through OpsRoute::string()');
    }
}

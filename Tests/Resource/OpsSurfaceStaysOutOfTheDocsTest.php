<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Resource;

use ApiPlatform\Metadata\ApiResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function class_exists;
use function dirname;
use function glob;
use function sprintf;

/**
 * The module's absence promise, derived instead of copied: every ops resource declares
 * `openapi: false`, so an app whose `/api/docs` stays public does not advertise the shape of its
 * cancel, redrive and crypto-shred endpoints. Ten hand-written declarations kept the promise by
 * discipline alone; an eleventh resource missing the line would publish its shape with nothing red.
 */
final class OpsSurfaceStaysOutOfTheDocsTest extends TestCase
{
    #[Test]
    public function every_ops_resource_declares_itself_out_of_openapi(): void
    {
        $files = glob(dirname(__DIR__, 2).'/Resource/*.php');
        self::assertNotFalse($files);

        $checked = 0;
        foreach ($files as $file) {
            $class = 'Storm\\ApiOps\\Resource\\'.basename($file, '.php');
            self::assertTrue(class_exists($class), $class);

            foreach (new ReflectionClass($class)->getAttributes(ApiResource::class) as $attribute) {
                self::assertFalse(
                    $attribute->newInstance()->getOpenapi(),
                    sprintf('%s must declare openapi: false — the ops surface is never advertised in the public API docs', $class),
                );
                $checked++;
            }
        }

        // the walk itself is guarded: an empty or misdirected glob would certify nothing green
        self::assertGreaterThan(8, $checked);
    }
}

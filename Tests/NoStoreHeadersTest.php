<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests;

use ApiPlatform\Metadata\Get;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\ApiOps\NoStoreHeaders;
use Storm\ApiOps\Resource\StreamResource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class NoStoreHeadersTest extends TestCase
{
    #[Test]
    public function an_ops_response_leaves_no_store_even_when_stamped_public(): void
    {
        // the app's global cache defaults already wrote the worst possible header: the defense
        // must overwrite, not merge; nothing of the public posture may survive
        $event = $this->eventFor(new Get(class: StreamResource::class));
        $event->getResponse()->headers->set('Cache-Control', 'public, max-age=60, s-maxage=3600');

        (new NoStoreHeaders)($event);

        self::assertSame('no-store, private', $event->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_foreign_resource_or_bare_route_stays_untouched(): void
    {
        // the defense is keyed on the OPERATION's resource class, never the path: an app resource
        // keeps whatever cache policy the app declared, and a non-API route is none of our business
        $foreign = $this->eventFor(new Get(class: stdClass::class));
        $foreign->getResponse()->headers->set('Cache-Control', 'public, max-age=60');
        (new NoStoreHeaders)($foreign);
        // Symfony normalizes the directive order on set(); untouched means still the public policy
        self::assertSame('max-age=60, public', $foreign->getResponse()->headers->get('Cache-Control'));

        $bare = $this->eventFor(operation: null);
        $bare->getResponse()->headers->set('Cache-Control', 'public, max-age=60');
        (new NoStoreHeaders)($bare);
        self::assertSame('max-age=60, public', $bare->getResponse()->headers->get('Cache-Control'));
    }

    private function eventFor(?Get $operation): ResponseEvent
    {
        $request = Request::create('/_storm/streams');

        if ($operation !== null) {
            $request->attributes->set('_api_operation', $operation);
        }

        $kernel = new class() implements HttpKernelInterface
        {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new RuntimeException('the fake kernel never handles');
            }
        };

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('[]'));
    }
}

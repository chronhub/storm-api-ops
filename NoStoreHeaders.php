<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use ApiPlatform\Metadata\HttpOperation;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

use function str_starts_with;

/**
 * The cache defense: every response served for an ApiOps resource leaves with
 * `Cache-Control: no-store, private`, whatever cache policy the application declared globally.
 * The resources carry no cache metadata of their own, so without this listener they would
 * inherit the app's API Platform defaults; an app that turns on a public shared cache for its
 * own resources would let a proxy store raw event payloads, aggregate snapshots, saga forensics
 * and lease owners, and serve one operator's session to the next until the TTL runs out.
 *
 * Keyed on the OPERATION's resource class, never on the request path: the resources declare
 * `/_storm/*` but the bridge mounts them under `/api`, and a path pattern that forgets the mount
 * prefix is exactly the misconfiguration this package must not reproduce. Runs late at a negative
 * priority so it wins over anything an earlier listener or API Platform's own header processor
 * stamped.
 */
#[AsEventListener(priority: -255)]
final readonly class NoStoreHeaders
{
    public function __invoke(ResponseEvent $event): void
    {
        $operation = $event->getRequest()->attributes->get('_api_operation');

        if (! $operation instanceof HttpOperation) {
            return;
        }

        $class = $operation->getClass();

        if ($class === null || ! str_starts_with($class, 'Storm\\ApiOps\\Resource\\')) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\View\OutboxFailedViewController;

/**
 * The route of the dead-letter SCREEN, declared through resource metadata like every route of this
 * surface, which is what keeps it out of the published docs and behind the same actor gate.
 */
#[ApiResource(
    shortName: 'StormOutboxFailedView',
    operations: [
        new Get(
            uriTemplate: '/_storm/view/outbox-failed',
            // the navigation generates by NAME; a generated route name would change under us
            name: 'storm_view_outbox_failed',
            controller: OutboxFailedViewController::class,
            read: false,
            output: false,
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    exceptionToStatus: [
        AnonymousReadRefused::class => 403,
    ],
)]
final readonly class OutboxFailedViewResource {}

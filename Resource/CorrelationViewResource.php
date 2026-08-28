<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\View\CorrelationViewController;

/**
 * The route of the correlation trace SCREEN, declared here so the page inherits the surface's
 * posture rather than inventing one.
 *
 * A spike, and the layer is a decision it forced: the screen must stand behind the same actor gate
 * as the reads it renders, and that gate lives in this package. The framework bundle's own ops
 * routes ship with no authentication by design, closed app-side by a firewall pattern, so a page
 * printing stored payloads does not belong among them.
 *
 * Declared through resource metadata like every other ops route, which is what keeps it out of the
 * published docs; `read` and `output` are off because the controller answers with rendered HTML,
 * not with a serialized resource.
 */
#[ApiResource(
    shortName: 'StormCorrelationView',
    operations: [
        new Get(
            uriTemplate: '/_storm/view/correlations',
            // the navigation generates by NAME; a generated route name would change under us
            name: 'storm_view_correlations',
            controller: CorrelationViewController::class,
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
final readonly class CorrelationViewResource {}

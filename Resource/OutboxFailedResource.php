<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\State\OutboxFailedProvider;
use Storm\ApiOps\State\PageWindow;

/**
 * One dead-lettered outbox row, the HTTP twin of `storm:outbox:failed`:
 *
 * - The row id and its global `position`
 * - The event `type`
 * - The `attempts` spent before the row was given up on
 * - The `lastError` that failed it, and the moment it `failedAt`
 *
 * The row carries no payload, as in the console twin: an operator triages a dead-letter on its cause
 * and its age, never its content. A screen that wants the payload would extend a settled seam rather
 * than reopen a question, since `PersonalDataVeil` already covers that read on the events endpoints.
 *
 * A keyset window over the relay's drain order, resumable through `after` on the row id, so a page
 * matches the order a requeue would republish in.
 *
 * @see \Storm\Chronicler\Outbox\FailedOutboxMessage the row this mirrors
 */
#[ApiResource(
    shortName: 'StormOutboxFailed',
    operations: [
        new GetCollection(
            uriTemplate: '/_storm/outbox/failed',
            paginationEnabled: false,
            provider: OutboxFailedProvider::class,
            parameters: [
                // the zero minimum compiles to no constraint, API Platform deriving bounds by truthiness; the
                // real guard is PageWindow::afterPosition's clamp, pinned by its own test
                'after' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 0], description: 'Resume strictly after this row id.'),
                'limit' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => PageWindow::MAX_LIMIT], description: 'Page size, capped server-side.'),
            ],
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
final readonly class OutboxFailedResource
{
    public function __construct(
        public int $id,
        public int $position,
        public string $type,
        public int $attempts,
        public ?string $lastError,
        public ?string $failedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StreamEventsProvider;

/**
 * One stored event as the hydrated current view: the read rides `StreamReader`, so aliases are
 * resolved and the payload is deserialized and upcast to the current shape.
 *
 * `payload` is the current event's `toPayload()` under the no-decrypt veil, where a `#[Personal]`
 * class's declared keys are overlaid from the stored row, the ciphered envelope, never the decrypted
 * values. The `headers` bag and `type` are the stored ones; `recorded_at` is store-generated. Payloads
 * and headers are what introspection serves, and exactly why this package is a conscious opt-in behind
 * the app's ops firewall.
 *
 * A broken alias or upcast chain surfaces here as a 500 an operator must see: this endpoint serves no
 * forensic raw-row view of the stored bytes.
 *
 * Stream and aggregate windows resume by global position through `after`, the store's append order.
 * The correlation window wraps these rows in {@see CorrelationEventsPageResource} with an explicit
 * truncation flag.
 */
#[ApiResource(
    shortName: 'StormStoredEvent',
    operations: [
        new GetCollection(
            uriTemplate: '/_storm/streams/{stream}/events',
            uriVariables: ['stream'],
            // a qualifier is free-form, since a slash is a valid StreamName byte: the default [^/]++
            // segment would make /streams announce names this endpoint can never read back
            requirements: ['stream' => '.+'],
            paginationEnabled: false,
            provider: StreamEventsProvider::class,
            parameters: [
                // the zero minimum compiles to no constraint, API Platform deriving bounds by truthiness; the
                // real guard is PageWindow::afterPosition's clamp, pinned by its own test
                'after' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 0], description: 'Resume strictly after this global position.'),
                'limit' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => PageWindow::MAX_LIMIT], description: 'Page size, capped server-side.'),
            ],
        ),
        new GetCollection(
            uriTemplate: '/_storm/aggregates/{category}/{id}/events',
            uriVariables: ['category', 'id'],
            requirements: ['id' => '.+'],
            paginationEnabled: false,
            provider: StreamEventsProvider::class,
            parameters: [
                // the zero minimum compiles to no constraint, API Platform deriving bounds by truthiness; the
                // real guard is PageWindow::afterPosition's clamp, pinned by its own test
                'after' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 0], description: 'Resume strictly after this global position.'),
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
        MalformedQueryParameter::class => 422,
    ],
)]
final readonly class StoredEventResource
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public int $position,
        public string $stream,
        public string $type,
        public array $payload,
        public array $headers,
        public string $recordedAt,
    ) {}
}

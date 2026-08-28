<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\State\PageWindow;
use Storm\ApiOps\State\StreamsProvider;

/**
 * One stream of the event store, as the ops directory sees it: name and last committed version,
 * read from the stream heads, never a scan of `event_store` itself.
 *
 * The collection is windowed, not paginated: `stream_heads` holds one row per stream and one
 * aggregate IS one stream, so the population is as large as the store's. Resume by passing the
 * last stream name seen as `after`; narrow to one lane with `category`.
 */
#[ApiResource(
    shortName: 'StormStream',
    operations: [
        new GetCollection(
            uriTemplate: '/_storm/streams',
            paginationEnabled: false,
            provider: StreamsProvider::class,
            parameters: [
                'category' => new QueryParameter(schema: ['type' => 'string'], description: 'Narrow to one category lane (the bare category stream plus every qualified one).'),
                'after' => new QueryParameter(schema: ['type' => 'string'], description: 'Resume strictly after this stream name.'),
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
final readonly class StreamResource
{
    public function __construct(
        public string $stream,
        public int $lastVersion,
    ) {}
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\State\SagaListingProvider;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;

/**
 * The sagas as an ops directory, the HTTP twin of `storm:saga:list`: the surface that answers "which
 * sagas are there" for a caller holding an incident and no correlation id. Its sibling
 * {@see SagaResource} answers "what happened to THIS one" and needs the correlation up front.
 *
 * An envelope rather than a bare array, the shape {@see SagaHistoryResource} already uses, and for the
 * reason the store computes: the window is read one row past the cap, so truncation is KNOWN. A
 * collection of exactly `limit` rows carrying no flag reads as the whole population, and there is no
 * cursor to page past the wall, only filters a caller does not know they need to narrow.
 *
 * Windowed, not paginated, like the streams directory: the population is as large as the app's saga
 * traffic. Rows come oldest-touched first, so the sagas an operator is hunting sit at the top of the
 * first window rather than at the end of the last.
 *
 * `idle_for` is seconds here where the console takes a compact `30m`/`6h`/`3d`: the parser for that
 * form lives in Clock, which is deliberately outside this package's dependency ceiling, and seconds
 * are the honest wire form for an HTTP query anyway.
 */
#[ApiResource(
    shortName: 'StormSagaListing',
    operations: [
        new Get(
            uriTemplate: '/_storm/sagas',
            provider: SagaListingProvider::class,
            parameters: [
                'type' => new QueryParameter(schema: ['type' => 'string'], description: 'Narrow to one workflow type.'),
                'status' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['running', 'completed', 'halted', 'compensated']], description: 'Narrow to one lifecycle status.'),
                'idle_for' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 31_536_000], description: 'Only sagas untouched for at least this many seconds (at most a year) — with status=running, the stuck-saga filter.'),
                'waived' => new QueryParameter(schema: ['type' => 'boolean'], description: 'Only sagas whose retry budget was waived.'),
                'limit' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => SagaInspectionGateway::MAX_LIMIT], description: 'Window size, capped server-side.'),
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
final readonly class SagaListingPageResource
{
    /**
     * @param  list<SagaListingResource>  $sagas  at most `$limit` rows, oldest-touched first
     * @param  bool  $truncated  true when at least one further saga matched beyond the cap
     * @param  positive-int  $limit  the cap actually applied, after the server-side clamp
     */
    public function __construct(
        public array $sagas,
        public bool $truncated,
        public int $limit,
    ) {}
}

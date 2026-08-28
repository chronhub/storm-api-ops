<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\State\SagaHistoryProvider;
use Storm\Telemetry\History\GenerationOutOfRange;
use Storm\Telemetry\History\WorkflowHistoryStore;

/**
 * One saga's recorded timeline, the HTTP twin of `storm:telemetry:history`: every `Saga*`
 * announcement that led to the state {@see SagaResource} shows. The instance row holds the present;
 * this holds how it got there.
 *
 * Served in the saga's own URI space rather than under a telemetry path, because that is where an
 * operator is when the question arises: they came from `/_storm/sagas`, they have a correlation,
 * and the next thing they want is its life. The rows come from Telemetry's `workflow_history`, which
 * this package reads across the seam the design nominated it to be.
 *
 * An ENVELOPE, not a bare collection, deliberately: the store's answer is more than its rows, and a
 * capped window that reads as a complete one is the lie an operator cannot recover from, so
 * `truncated` and `limit` ride along, and `availability` names what an empty `records` means. Saga
 * history is opt-in three times over, and each miss produces a DIFFERENT empty:
 *
 * - The package must be installed;
 * - `storm:telemetry:install` must have been run;
 * - The `SagaHistorySink` alias must point at the table sink.
 *
 * Ordered by the saga's own announce time, not by arrival, so the order survives the async sink.
 */
#[ApiResource(
    shortName: 'StormSagaHistory',
    operations: [
        new Get(
            uriTemplate: '/_storm/sagas/{correlationId}/history',
            uriVariables: ['correlationId'],
            provider: SagaHistoryProvider::class,
            parameters: [
                'type' => new QueryParameter(schema: ['type' => 'string'], description: 'The workflow type — narrows, and lets the read use the index on a large table.'),
                'generation' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1], description: 'One run of a reused correlation; omit to aggregate every run it ever had.'),
                'limit' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => WorkflowHistoryStore::MAX_LIMIT], description: 'Window size, capped server-side.'),
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
        GenerationOutOfRange::class => 422,
    ],
)]
final readonly class SagaHistoryResource
{
    /**
     * @param  list<SagaHistoryRecordResource>  $records  oldest first, by the saga's own clock
     * @param  bool  $truncated  true when the window cut the timeline: more records exist past `limit`
     * @param  int  $limit  the window actually served, after the server-side cap
     * @param  string  $availability  the table-level fact behind an empty `records`, a
     *                                {@see \Storm\Telemetry\History\HistoryAvailability} value;
     *                                `has_rows` whenever records came back
     */
    public function __construct(
        public string $correlationId,
        public array $records,
        public bool $truncated,
        public int $limit,
        public string $availability,
    ) {}
}

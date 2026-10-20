<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\State\BacklogProvider;

/**
 * What is waiting, and how long the oldest of it has waited, as one read.
 *
 * The numbers are the metrics collectors' own, re-served as JSON for a caller that reads the rest of
 * this surface as JSON; the text exposition at `/_storm/metrics` keeps its single format, a decision
 * recorded there and not reopened here. Only the seven backlog families are served: serving every
 * family would make this a second metrics endpoint under another content type.
 *
 * A screen must not present this answer as more than it is. These are storm's own queues, the event
 * outbox, the saga command outbox and the idempotency inbox. What a broker holds is invisible here:
 * nothing in this framework reads a transport, so a backlog that has left the outbox and not yet been
 * consumed appears nowhere on this page.
 *
 * `degraded` names collectors that failed or collided during the read. A block missing because its
 * collector threw looks exactly like an empty queue, and that difference decides the reading, so it is
 * named rather than left to be inferred from an absence.
 */
#[ApiResource(
    shortName: 'StormBacklog',
    operations: [
        new Get(
            uriTemplate: '/_storm/backlog',
            provider: BacklogProvider::class,
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
final readonly class BacklogResource
{
    /**
     * @param  list<array{
     *     name: string,
     *     help: string,
     *     samples: list<array{labels: array<string, string>, value: int|float}>
     * }>  $families
     * @param  list<string>  $degraded  collector short names whose block is absent from `families`
     */
    public function __construct(
        public array $families,
        public array $degraded,
    ) {}
}

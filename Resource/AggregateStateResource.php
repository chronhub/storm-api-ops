<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AggregateFoldRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\State\AggregateStateProvider;

/**
 * One aggregate in introspection: category, id, current version, and, when the aggregate is
 * snapshotable, its state rendered by the aggregate's own `toSnapshot()` representation. A
 * non-snapshotable aggregate honestly answers `state: null`, version and existence only, rather
 * than inventing a normalization the framework refuses. No `storm:*` command renders an
 * aggregate's fold, so this read has no console twin. A `version` query parameter serves the same
 * shape at that past version, a read-only diagnostic folded by today's code.
 *
 * Ops zone only, by doctrine: an app-facing read that "would need" this endpoint is a missing
 * query; the aggregate over HTTP exists solely as introspection.
 */
#[ApiResource(
    shortName: 'StormAggregate',
    operations: [
        new Get(
            uriTemplate: '/_storm/aggregates/{category}/{id}',
            uriVariables: ['category', 'id'],
            // an AggregateIdentity's string form is the app's own: a reserved URI byte in it must
            // not make the aggregate unaddressable; the category regex, by contrast, never
            // carries a slash. The one reserved suffix is `/events`: this route registers before
            // the history window's and would swallow it greedily, so the lookbehind hands those
            // paths back; an id ENDING in "/events" reads its history, never its state
            requirements: ['id' => '.+(?<!/events)'],
            provider: AggregateStateProvider::class,
            parameters: [
                'version' => new QueryParameter(
                    description: 'Inspect state at this positive historical version using current code.',
                    constraints: [],
                    castToArray: false,
                    castToNativeType: false,
                ),
            ],
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    // The provider validates the raw `version` after its actor gate, preserving the explicit 400
    // refusal instead of automatic schema validation or coercion before the read boundary
    exceptionToStatus: [
        AnonymousReadRefused::class => 403,
        MalformedQueryParameter::class => 400,
        AggregateFoldRefused::class => 422,
    ],
)]
final readonly class AggregateStateResource
{
    /**
     * @param  array<string, mixed>|null  $state
     */
    public function __construct(
        public string $category,
        public string $id,
        public int $version,
        public ?array $state,
    ) {}
}

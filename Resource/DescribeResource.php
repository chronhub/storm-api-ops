<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\UnknownDescribeSection;
use Storm\ApiOps\NoStoreHeaders;
use Storm\ApiOps\State\DescribeProvider;

/**
 * The compiled Storm wiring over HTTP, the twin of `storm:describe`: it serves the EXACT document
 * the console renders, same descriptor, same sections, same sorted, diffable shape. Static by
 * the descriptor's contract, never live: everything here is an in-memory registry or a compiled
 * container parameter, so this endpoint answers with the database and the broker down, and the
 * runtime questions of checkpoints, lags and instances stay with the inspection resources next
 * door.
 *
 * A singleton read: the route carries no identifier because a compiled container has exactly one
 * description. `?section=` mirrors the console's `--section` contract: the fragment stays under
 * its own key, so a consumer never has to know which channel produced it; an unknown name is
 * refused loud with a 422, the surface's invalid-value status, listing the valid ones. The
 * one-key rendering rides `skip_null_values`: the DTO's eight section properties are nullable,
 * the filtered-out ones stay null, and only the TOP-LEVEL null attributes are skipped; nulls
 * inside a section are document content and normalize untouched.
 *
 * Pure read, so no actor gate and no audit record, the module's line: audits name who ACTED, and
 * nothing acts here. Access stays the app's ops firewall: the documented `^/(api/)?_storm`
 * access_control line covers this path like every other, and {@see NoStoreHeaders} stamps the
 * response no-store like every other.
 *
 * @see \Storm\Symfony\Describe\StormDescriptor the document and its static-only sources
 * @see \Storm\Symfony\Console\DescribeCommand the console rendering this twins
 */
#[ApiResource(
    shortName: 'StormDescribe',
    operations: [
        new Get(
            uriTemplate: '/_storm/describe',
            normalizationContext: ['skip_null_values' => true],
            // the ?section= rendering: skip the filtered-out null section properties, so the
            // fragment serves alone under its key; nested nulls are content and stay
            provider: DescribeProvider::class,
            parameters: [
                'section' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'Render one section only, under its key — the console `--section` contract. An unknown name is refused, listing the valid ones.',
                ),
            ],
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    exceptionToStatus: [
        UnknownDescribeSection::class => 422,
    ],
)]
final readonly class DescribeResource
{
    /**
     * Eight properties, one per section, named and ordered exactly as the canonical document
     * renders them: the property names ARE the document keys, no name conversion in between.
     * Null never means "empty" here, it means "filtered out by `?section=`": an empty or absent
     * capability still renders its stable shape inside the section, per the descriptor.
     *
     * @param  array<string, mixed>|null  $meta  format schema_version, PHP version, environment
     * @param  list<array<string, mixed>>|null  $event_types  the catalog: alias/class/version/replaces + consumers
     * @param  array<string, mixed>|null  $workflows  availability + the attribute-derived definitions
     * @param  list<array<string, mixed>>|null  $projections  one record per registered projection
     * @param  array<string, mixed>|null  $buses  the bundle's bus ids + the compiled saga priority policy
     * @param  array<string, mixed>|null  $grants  the two compiled grant parameters
     * @param  list<array<string, mixed>>|null  $schemas  table names per owning module
     * @param  array<string, mixed>|null  $health  the tagged check names, never probed
     */
    public function __construct(
        public ?array $meta,
        public ?array $event_types,
        public ?array $workflows,
        public ?array $projections,
        public ?array $buses,
        public ?array $grants,
        public ?array $schemas,
        public ?array $health,
    ) {}
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Override;
use Storm\ApiOps\Error\UnknownDescribeSection;
use Storm\ApiOps\Resource\DescribeResource;
use Storm\Symfony\Describe\StormDescriptor;

use function get_debug_type;
use function in_array;
use function is_string;

/**
 * The compiled wiring as the HTTP twin of `storm:describe`: delegate to the SAME
 * `StormDescriptor` the console renders and reshape nothing, one assembly and two channels, so
 * the two documents can never diverge. The section filter is the console contract replayed:
 * validate against the descriptor's own section list, refuse loud with the valid names, then
 * serve the full document or the one section under its key, where the null properties are the
 * resource's "filtered out", skipped at normalization.
 *
 * The no-DB contract holds HERE too: this provider touches no store, no connection, no broker;
 * the descriptor is fed registries and compiled parameters only, so this endpoint answers on a
 * kernel whose database is unreachable. That is what makes it safe to call from a build, a
 * probe, or a tool pointed at a degraded deployment.
 *
 * IT IS THE ONE PROVIDER WITHOUT THE ACTOR GATE, and the document it serves is WHOLE: the
 * `event_types` section carries each marked class's compiled `#[Personal]` declaration, its subject
 * key, its protected key names and their fallbacks. Two narrowings were weighed and refused, and the
 * reasons live here because this is the code that embodies the decision:
 *
 * - Rendering only the FACT that a class is marked. Naming a protection is the same honesty the
 *   render veil already practices, where a ciphered envelope on an operator's screen is safe because
 *   its presence PROVES the field is protected at rest. A document that hid half a declaration would
 *   know more than it says, and no reader could tell an empty key list from a masked one. This is also
 *   the only surface where a declaration can be checked against intent.
 * - Putting this provider under the read gate on a third knob. That knob only helps if it refuses by
 *   default, which is precisely the build-and-probe case above: the endpoint has to answer while the
 *   identity substrate is down, and a gate cannot both fail closed and survive that outage.
 *
 * What the document therefore needs is an `access_control` rule at the mount point, exactly as every
 * other endpoint of this surface does; the difference is that here it is the ONLY layer. An app that
 * mounts this zone and forgets the rule publishes its wiring, and that is a consumer-side invariant
 * the package cannot hold for it.
 *
 * @implements ProviderInterface<DescribeResource>
 */
final readonly class DescribeProvider implements ProviderInterface
{
    public function __construct(
        private StormDescriptor $descriptor,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws UnknownDescribeSection when `?section=` names no section of the document, a 422 by
     *                                declaration on the resource, listing the valid names
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DescribeResource
    {
        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        $section = null;
        if (($filters['section'] ?? null) !== null) {
            // a non-string (?section[]=…) can never name a section: refused by its type's name
            $section = is_string($filters['section']) ? $filters['section'] : get_debug_type($filters['section']);

            if (! in_array($section, StormDescriptor::SECTIONS, true)) {
                throw UnknownDescribeSection::named($section, StormDescriptor::SECTIONS);
            }
        }

        $document = $this->descriptor->describe();

        return new DescribeResource(
            meta: $section === null || $section === 'meta' ? $document['meta'] : null,
            event_types: $section === null || $section === 'event_types' ? $document['event_types'] : null,
            workflows: $section === null || $section === 'workflows' ? $document['workflows'] : null,
            projections: $section === null || $section === 'projections' ? $document['projections'] : null,
            buses: $section === null || $section === 'buses' ? $document['buses'] : null,
            grants: $section === null || $section === 'grants' ? $document['grants'] : null,
            schemas: $section === null || $section === 'schemas' ? $document['schemas'] : null,
            health: $section === null || $section === 'health' ? $document['health'] : null,
        );
    }
}

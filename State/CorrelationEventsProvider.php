<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\StoredEventResource;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\InvalidPosition;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_string;
use function sprintf;
use function trim;

/**
 * Every stored event carrying one of the given correlation ids, the HTTP window over the same read
 * `storm:events:inspect --recipe correlation_trace` serves. The predicate and its index-matching
 * spelling belong to `CorrelationFeedFilter`; this provider parses the set and veils the payload,
 * exactly as the stream window does.
 *
 * The set is the contract. Several ids are traced together because a saga child carries its own
 * correlation id, so a lineage is a SET resolved where it is known, through the `children` of a
 * saga snapshot, and passed in whole. This surface offers no lineage preset and will not grow one:
 * a preset meaning the whole tree wherever coordination happens to be installed would mean two
 * different things in two applications.
 *
 * There is no resume cursor, and the omission is the filter's shape rather than an oversight: it
 * carries a set of ids and nothing else. A correlation footprint is bounded by what one business
 * transaction wrote, so the server cap is the guard; a caller who reaches it is asking a question
 * this window is not the right shape for.
 *
 * @implements ProviderInterface<StoredEventResource>
 */
final readonly class CorrelationEventsProvider implements ProviderInterface
{
    public function __construct(
        private StreamReader $reader,
        private OpsActorGate $gate,
        private OpsAuditLog $audit,
        private StoredEventResourceFactory $resources = new StoredEventResourceFactory,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<StoredEventResource>
     *
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws MalformedQueryParameter when `ids` is absent, blank, or holds nothing but separators
     * @throws InvalidPosition when a stored row's position is malformed
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when a stored event failed to deserialize
     * @throws UnknownEventType when a stored type resolves to no known event class
     * @throws UndecodableRow when this runtime cannot decode a stored row
     * @throws NotADomainEvent when a stored row wraps a non-event, a corrupt read
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        // the gate stands ahead of BOTH the store and the parse: an unnamed caller learns nothing,
        // not even that its set was malformed, and the refusal names the raw set it asked for
        $raw = $filters['ids'] ?? null;
        $this->gate->assertOwnedIdentityForRead('correlations.read', is_string($raw) ? $raw : '');

        $ids = $this->setFrom($filters);

        $items = [];
        foreach ($this->reader->retrieveByFilter(new CorrelationFeedFilter($ids, PageWindow::limit($filters))) as $record) {
            $items[] = $this->resources->fromRecord($record);
        }

        // the payload-bearing read leaves a trace, the same reason the stream window does: hydrated
        // events served over HTTP are what a drained store would otherwise never show
        $this->audit->record('correlations.read', implode(',', $ids), sprintf('served %d event(s)', count($items)));

        return $items;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return non-empty-list<string>
     *
     * @throws MalformedQueryParameter when the parameter names no usable id
     */
    private function setFrom(array $filters): array
    {
        $raw = $filters['ids'] ?? null;

        $ids = explode(',', is_string($raw) ? $raw : '')
                |> (static fn ($x) => array_map(trim(...), $x))
                |> array_filter(...)
                |> array_values(...);

        if ($ids === []) {
            // a narrowing parameter dropped WIDENS the request, and this one would widen to the
            // whole store: refusing is the only reading that cannot mislead
            throw MalformedQueryParameter::expectingANonEmptySet('ids');
        }

        return $ids;
    }
}

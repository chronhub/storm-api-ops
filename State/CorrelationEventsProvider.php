<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\Error\OperatorPermissionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\CorrelationEventsPageResource;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\InvalidPosition;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Throwable;

use function count;
use function implode;
use function is_string;
use function sprintf;

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
 * The window reads one extra record to establish truncation, then returns at most the applied
 * limit. The extra record is not converted to a response payload. No resume cursor is offered;
 * `truncated` distinguishes an incomplete trace from an exactly full result.
 *
 * @implements ProviderInterface<CorrelationEventsPageResource>
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
     * @throws OperatorPermissionRefused when the application does not grant operator access
     * @throws Throwable when an application identity or permission backend cannot answer
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws MalformedQueryParameter when `ids` is absent, blank, holds nothing but separators, or
     *                                 names more ids than the predicate width admits
     * @throws InvalidPosition when a stored row's position is malformed
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when a stored event failed to deserialize
     * @throws UnknownEventType when a stored type resolves to no known event class
     * @throws UndecodableRow when this runtime cannot decode a stored row
     * @throws NotADomainEvent when a stored row wraps a non-event, a corrupt read
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorrelationEventsPageResource
    {
        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        // the gate stands ahead of BOTH the store and the parse: an unnamed caller learns nothing,
        // not even that its set was malformed, and the refusal names the raw set it asked for
        $raw = $filters['ids'] ?? null;
        $this->gate->assertOwnedIdentityForRead('correlations.read', is_string($raw) ? $raw : '');

        $ids = $this->setFrom($filters);

        $limit = PageWindow::limit($filters);
        $items = [];
        $truncated = false;
        foreach ($this->reader->retrieveByFilter(new CorrelationFeedFilter($ids, $limit + 1)) as $record) {
            if (count($items) === $limit) {
                $truncated = true;

                break;
            }
            $items[] = $this->resources->fromRecord($record);
        }

        // the payload-bearing read leaves a trace, the same reason the stream window does: hydrated
        // events served over HTTP are what a drained store would otherwise never show
        $this->audit->record('correlations.read', implode(',', $ids), sprintf('served %d event(s)', count($items)));

        return new CorrelationEventsPageResource($items, $limit, $truncated);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return non-empty-list<string>
     *
     * @throws MalformedQueryParameter when the parameter names no usable id, or more than the width admits
     */
    private function setFrom(array $filters): array
    {
        $ids = CorrelationIdSet::parse($filters['ids'] ?? null);

        if ($ids === []) {
            // a narrowing parameter dropped WIDENS the request, and this one would widen to the
            // whole store: refusing is the only reading that cannot mislead
            throw MalformedQueryParameter::expectingANonEmptySet('ids');
        }

        if (CorrelationIdSet::isTooWide($ids)) {
            // row truncation must not disguise a different query: an oversized id set is refused
            // whole rather than silently narrowing which correlations the window traces
            throw MalformedQueryParameter::expectingANarrowerSet('ids', CorrelationIdSet::MAX_IDS, count($ids));
        }

        return $ids;
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use const PHP_INT_MAX;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\StoredEventResource;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Query\EventFeedFilter;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\InvalidPosition;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Contracts\Stream\InvalidStreamException;
use Storm\Stream\StreamName;

/**
 * Stored events as an ops collection, the HTTP twin of `storm:events:inspect`: one provider for
 * both windows, a stream's events by name and an aggregate's history by category and id as its
 * qualified stream. A HYDRATED CURRENT view, not a forensic one: the read rides `StreamReader`,
 * so aliases resolve, upcasters run, and the served payload is the current event's `toPayload()`
 * through the {@see \Storm\Chronicler\Record\PersonalDataVeil}: a `#[Personal]` class's declared keys show
 * what the store holds, the envelope, never the decrypted values; inspection reads without
 * decrypting, by doctrine. The stored headers ride along untouched. Bounded by construction
 * through `EventFeedFilter` and the server-owned window, read to the store's head: introspection
 * wants the latest, and a transient near-head gap is harmless to a browser, so no safe-head bound
 * is applied, which is a projector's discipline, not an inspector's.
 *
 * A path that cannot form a stream name answers an empty window, like a stream that has none:
 * for collections, "nothing there" is the answer. Corrupt stored data, an unknown type or missing
 * upcaster, surfaces as the raw failure: a 500 an operator must see, never an empty page.
 *
 * @implements ProviderInterface<StoredEventResource>
 */
final readonly class StreamEventsProvider implements ProviderInterface
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
     * @throws InvalidPosition when a stored row's position is malformed
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when a stored event failed to deserialize
     * @throws UnknownEventType when a stored type resolves to no known event class
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     * @throws NotADomainEvent when a stored row wraps a non-event, a corrupt read
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $subject = (string) ($uriVariables['stream'] ?? (($uriVariables['category'] ?? '').'-'.($uriVariables['id'] ?? '')));
        $this->gate->assertOwnedIdentityForRead('events.read', $subject);

        $scope = $this->scopeFrom($uriVariables);

        if ($scope === null) {
            return [];
        }

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        $filter = new EventFeedFilter($scope);
        $filter->window(PageWindow::afterPosition($filters), PHP_INT_MAX, PageWindow::limit($filters));

        $items = [];
        foreach ($this->reader->retrieveByFilter($filter) as $record) {
            $items[] = $this->resources->fromRecord($record);
        }

        // the payload-bearing read leaves a trace: hydrated events served over HTTP are the one
        // thing a drained store would otherwise never show in the module's own audit channel
        $this->audit->record('events.read', $scope->toString(), sprintf('served %d event(s)', count($items)));

        return $items;
    }

    /**
     * @param  array<string, mixed>  $uriVariables
     */
    private function scopeFrom(array $uriVariables): ?StreamName
    {
        try {
            if (isset($uriVariables['category'], $uriVariables['id'])) {
                return new StreamName((string) $uriVariables['category'])->withQualifier((string) $uriVariables['id']);
            }

            return new StreamName((string) ($uriVariables['stream'] ?? ''));
        } catch (InvalidStreamException) {
            return null;
        }
    }
}

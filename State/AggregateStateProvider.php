<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Storm\AggregateRepository\AggregateRepositoryManager;
use Storm\AggregateRepository\Snapshot\PersonalDataSnapshotGuard;
use Storm\ApiOps\AggregateCatalog;
use Storm\ApiOps\Error\AggregateFoldRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\AggregateStateResource;
use Storm\Chronicler\Directory\StreamHeadStore;
use Storm\Chronicler\Exception\StorageFailure as StorageFailureException;
use Storm\Contracts\Aggregate\CorruptAggregateHistory;
use Storm\Contracts\Aggregate\InvalidAggregateIdentity;
use Storm\Contracts\Aggregate\SnapshotableAggregateRoot;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Stream\StreamName;

/**
 * One aggregate for introspection: resolve the category through the app's own `storm.aggregates`
 * declaration, then pay only for what the answer needs. A snapshotable aggregate reconstitutes
 * through the real repository, snapshot-accelerated, and answers its `toSnapshot()` state. A
 * non-snapshotable one exposes no state at all, so no replay runs: existence and version come
 * from the stream head, one indexed row, the same watermark the OCC append maintains. An HTTP GET
 * must never fold an unbounded history just to say `state: null`.
 *
 * Two refusals stand before the snapshotable fold, both 422 and both decided on one indexed row: a
 * head past the replay ceiling, and a stream carrying a `#[Personal]` event, whose fold would render
 * the subject's keys decrypted into an HTTP response.
 *
 * Every miss becomes a null and API Platform's native 404: an unknown category, an id that cannot
 * parse, no stream. For an ops browser each names nothing, and distinguishing "malformed" from
 * "absent" would only teach a prober which categories exist. Corrupt history surfaces raw on the
 * snapshotable path: a 500 an operator must see.
 *
 * Every answer leaves an audit line, the served state, the existence-and-version answer and both
 * refusals alike: existence is the very information the uniform 404 shields, so an actor
 * enumerating it must never be invisible in the module's own channel.
 *
 * @implements ProviderInterface<AggregateStateResource>
 */
final readonly class AggregateStateProvider implements ProviderInterface
{
    /**
     * The snapshotable fold's ceiling, on the stream head version: the head bounds the WORST
     * replay, a stale or absent snapshot, while a fresh snapshot shortens the real one; a
     * generous approximation that keeps the one uncapped read off this HTTP surface. No `storm:*`
     * verb folds an aggregate, so the refusal points at the history the fold is made of rather than
     * at a command that does not exist.
     */
    public const int MAX_FOLD_VERSIONS = 100_000;

    public function __construct(
        private AggregateCatalog $catalog,
        private AggregateRepositoryManager $aggregates,
        private StreamHeadStore $heads,
        private PersonalDataSnapshotGuard $personalData,
        private OpsActorGate $gate,
        private OpsAuditLog $audit,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws AggregateFoldRefused when the stream head exceeds the fold ceiling, or when the stream carries a `#[Personal]` event whose fold would render it decrypted
     * @throws StorageFailure on a store read or deserialization failure
     * @throws UnknownEventType when a stored event type resolves to no known event class on the snapshotable fold
     * @throws CorruptAggregateHistory when the replay contradicts its own version header on the snapshotable fold
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AggregateStateResource
    {
        // @infection-ignore-all equivalent: both casts serve the ANALYSER over a mixed bag the
        // router only ever fills with strings, and the default is already one; the values reach a
        // string parameter and a concatenation either way, so no reader can tell them apart
        $category = (string) ($uriVariables['category'] ?? '');
        // @infection-ignore-all equivalent: the id's cast, same reason as the category's above
        $this->gate->assertOwnedIdentityForRead('aggregate.read', $category.'-'.(string) ($uriVariables['id'] ?? ''));

        $entry = $this->catalog->entryFor($category);

        if ($entry === null) {
            return null;
        }

        try {
            $id = $entry['id']::fromString((string) ($uriVariables['id'] ?? ''));
        } catch (InvalidAggregateIdentity) {
            return null;
        }

        if (! is_subclass_of($entry['class'], SnapshotableAggregateRoot::class)) {
            // the same stream identity the repository would build, head row, zero replay; and a
            // poisoned event in the history cannot 500 a version-and-existence answer. No personal
            // probe here: a class that exposes no state can carry nothing out, marked or not.
            $version = $this->heads->lastVersion(
                new StreamName($category)->withQualifier($id->toString())->toString(),
            );

            if ($version === 0) {
                return null;
            }

            // existence and version are the class of information the uniform 404 shields from the
            // anonymous: an actor enumerating them is never invisible in the module's own channel
            $this->audit->record('aggregate.read', $category.'-'.$id->toString(), sprintf('served existence at version %d', $version));

            return new AggregateStateResource(
                category: $category,
                id: $id->toString(),
                version: $version,
                state: null,
            );
        }

        $stream = new StreamName($category)->withQualifier($id->toString())->toString();

        // the ceiling before the fold: the head is one indexed row and bounds the worst replay
        $headVersion = $this->heads->lastVersion($stream);

        if ($headVersion > self::MAX_FOLD_VERSIONS) {
            $refusal = AggregateFoldRefused::tooLong($category, $id->toString(), $headVersion, self::MAX_FOLD_VERSIONS);
            $this->audit->record('aggregate.read', $category.'-'.$id->toString(), 'refused: '.$refusal->getMessage());

            throw $refusal;
        }

        // The crypto-shredding exclusion, on the SAME probe the sweep uses to refuse writing a
        // snapshot to disk. A fold renders declared keys decrypted, so this surface would serve the
        // artifact the guard exists to prevent; the check is one indexed row against what is actually
        // stored, and it runs BEFORE the replay it makes pointless.
        try {
            $offendingType = $this->personalData->refusal($stream);
        } catch (DbalException $e) {
            // the guard probes the store with raw DBAL, as the module's read edges do; this surface
            // owes its caller the boundary type its own clause names, not a driver's
            throw StorageFailureException::wrap('probe personal data on '.$stream, $e);
        }

        if ($offendingType !== null) {
            $refusal = AggregateFoldRefused::personalDataInState($category, $id->toString(), $offendingType);
            $this->audit->record('aggregate.read', $category.'-'.$id->toString(), 'refused: '.$refusal->getMessage());

            throw $refusal;
        }

        $aggregate = $this->aggregates->for($entry['class'])->retrieve($id);

        if ($aggregate === null) {
            return null;
        }

        // the payload-bearing read leaves a trace, the twin of the event feed's audit line
        $this->audit->record('aggregate.read', $category.'-'.$id->toString(), sprintf('served at version %d', $aggregate->version()));

        // the guard above already proved the class snapshotable; the runtime check would be dead
        return new AggregateStateResource(
            category: $category,
            id: $id->toString(),
            version: $aggregate->version(),
            state: $aggregate instanceof SnapshotableAggregateRoot ? $aggregate->toSnapshot() : null, // @phpstan-ignore instanceof.alwaysTrue (belt for a catalog entry lying about its class at runtime)
        );
    }
}

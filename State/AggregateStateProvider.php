<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Storm\AggregateRepository\AggregateRepositoryManager;
use Storm\AggregateRepository\HistoricalAggregateInspector;
use Storm\AggregateRepository\Snapshot\PersonalDataSnapshotGuard;
use Storm\ApiOps\AggregateCatalog;
use Storm\ApiOps\Error\AggregateFoldRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\Error\OperatorPermissionRefused;
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
use Storm\Support\Console\PositiveIntOption;
use Throwable;

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
 * the subject's keys decrypted into an HTTP response. The personal-data probe runs again after
 * state materialization, before success auditing or publication, to catch appends during replay.
 *
 * A `version` query parameter asks for the state at that past version instead, a diagnostic that
 * leaves the current read above untouched. It is parsed strictly, a malformed value being a 400,
 * and a version past the observed head is a 404. The snapshotable answer comes from the
 * repository's `HistoricalAggregateInspector`, folded from version 1 by today's code and upcasters
 * with no snapshot involved, behind the same personal-data refusal and a ceiling on the requested
 * version. The non-snapshotable answer stays the head-backed existence answer at that version.
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
     * @throws OperatorPermissionRefused when the application does not grant operator access
     * @throws Throwable when an application identity or permission backend cannot answer
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws MalformedQueryParameter when `version` is present and is not a positive integer, a 400
     * @throws AggregateFoldRefused when the stream head, or the requested `version`, exceeds the fold ceiling, or when the stream carries a `#[Personal]` event whose fold would render it decrypted
     * @throws StorageFailure on a store read or deserialization failure
     * @throws UnknownEventType when a stored event type resolves to no known event class on the snapshotable fold
     * @throws CorruptAggregateHistory when the replay contradicts its own version header on the snapshotable fold, or stops before a requested `version` the head has passed
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

        $requested = $this->requestedVersion($context);
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

            if ($requested !== null) {
                // a head at or past the target proves the version existed under the head
                // invariant; nothing is decoded, so this answer never attests the events themselves
                if ($version < $requested) {
                    return null;
                }

                $this->audit->record('aggregate.read', $category.'-'.$id->toString(), sprintf('served existence at historical version %d', $requested));

                return new AggregateStateResource(
                    category: $category,
                    id: $id->toString(),
                    version: $requested,
                    state: null,
                );
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

        // a historical fold replays exactly the requested length, so its ceiling is decided on the
        // target alone, before any read
        if ($requested !== null && $requested > HistoricalAggregateInspector::MAX_VERSIONS) {
            $refusal = AggregateFoldRefused::versionPastCeiling($category, $id->toString(), $requested, HistoricalAggregateInspector::MAX_VERSIONS);
            $this->audit->record('aggregate.read', $category.'-'.$id->toString(), 'refused: '.$refusal->getMessage());

            throw $refusal;
        }

        // the ceiling before the fold: the head is one indexed row and bounds the worst replay
        $headVersion = $this->heads->lastVersion($stream);

        if ($requested === null && $headVersion > self::MAX_FOLD_VERSIONS) {
            $refusal = AggregateFoldRefused::tooLong($category, $id->toString(), $headVersion, self::MAX_FOLD_VERSIONS);
            $this->audit->record('aggregate.read', $category.'-'.$id->toString(), 'refused: '.$refusal->getMessage());

            throw $refusal;
        }

        // a version the observed head has not reached does not exist yet: the same silence as an
        // absent stream, decided before the personal-data probe and the fold it would make pointless
        if ($requested !== null && $headVersion < $requested) {
            return null;
        }

        // Refuse known personal streams before paying for a fold, then recheck the completed
        // state before exposing it: a concurrent append can enter the replay after this probe.
        $this->assertPublicState($stream, $category, $id->toString());

        if ($requested !== null) {
            // the repository boundary's read-only fold, never a snapshot and never an aggregate:
            // the probe above covered the whole stream, so a personal event recorded after the
            // target refuses too, and an append after the observed head cannot enter a read
            // bounded at the target
            $historical = $this->aggregates->inspectorFor($entry['class'])->stateAt($id, $requested);
            $this->assertPublicState($stream, $category, $id->toString());
            $this->audit->record('aggregate.read', $category.'-'.$id->toString(), sprintf('served historical state at version %d', $historical->version));

            return new AggregateStateResource(
                category: $category,
                id: $id->toString(),
                version: $historical->version,
                state: $historical->state,
            );
        }

        $aggregate = $this->aggregates->for($entry['class'])->retrieve($id);

        if ($aggregate === null) {
            return null;
        }

        // Materialize before the final probe so later appends cannot change the response state.
        $state = $aggregate instanceof SnapshotableAggregateRoot ? $aggregate->toSnapshot() : null; // @phpstan-ignore instanceof.alwaysTrue (belt for a catalog entry lying about its class at runtime)
        $this->assertPublicState($stream, $category, $id->toString());

        // the payload-bearing read leaves a trace, the twin of the event feed's audit line
        $this->audit->record('aggregate.read', $category.'-'.$id->toString(), sprintf('served at version %d', $aggregate->version()));

        return new AggregateStateResource(
            category: $category,
            id: $id->toString(),
            version: $aggregate->version(),
            state: $state,
        );
    }

    private function assertPublicState(string $stream, string $category, string $id): void
    {
        try {
            $offendingType = $this->personalData->refusal($stream);
        } catch (DbalException $e) {
            // the guard probes the store with raw DBAL, as the module's read edges do; this surface
            // owes its caller the boundary type its own clause names, not a driver's
            throw StorageFailureException::wrap('probe personal data on '.$stream, $e);
        }

        if ($offendingType !== null) {
            $refusal = AggregateFoldRefused::personalDataInState($category, $id, $offendingType);
            $this->audit->record('aggregate.read', $category.'-'.$id, 'refused: '.$refusal->getMessage());

            throw $refusal;
        }
    }

    /**
     * The `version` query parameter, null when absent.
     *
     * Present means integral in form and positive: `null`, an empty value, an array, a fraction, a
     * sign or a number an int cannot hold are refused, never dropped, since a dropped target would
     * silently serve the current state as the answer to a historical question.
     *
     * @param  array<string, mixed>  $context
     * @return positive-int|null
     *
     * @throws MalformedQueryParameter when `version` is present and is not a positive integer
     */
    private function requestedVersion(array $context): ?int
    {
        $filters = $context['filters'] ?? [];

        if (! is_array($filters) || ! array_key_exists('version', $filters)) {
            return null;
        }

        $raw = $filters['version'];
        $version = PositiveIntOption::parse($raw);

        if ($version === null) {
            // @infection-ignore-all equivalent: `sprintf` renders a scalar identically with or
            // without the cast; the cast serves the analyzer, the branch beside it carries the type
            throw MalformedQueryParameter::expectingAPositiveInteger('version', is_scalar($raw) ? (string) $raw : get_debug_type($raw));
        }

        return $version;
    }
}

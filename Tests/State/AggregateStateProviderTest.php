<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Get;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Storm\AggregateRepository\AggregateRepositoryManager;
use Storm\AggregateRepository\HistoricalAggregateInspector;
use Storm\AggregateRepository\Snapshot\PersonalDataSnapshotGuard;
use Storm\AggregateRepository\Snapshot\Snapshot;
use Storm\AggregateRepository\Snapshot\SnapshotStore;
use Storm\ApiOps\AggregateCatalog;
use Storm\ApiOps\Error\AggregateFoldRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\AggregateStateProvider;
use Storm\ApiOps\Tests\Fixture\OpsAggregateId;
use Storm\ApiOps\Tests\Fixture\OpsSnapshotAggregate;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Chronicler\Directory\StreamHeadStore;
use Storm\Chronicler\Store\DecisionAppend;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Contracts\Aggregate\CorruptAggregateHistory;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Message\MessageEnricher;

/**
 * The aggregate introspection read, which had no unit suite: every mutant of this provider was
 * absent from the module's field rather than surviving in it, a file with no coverage generating
 * none at all.
 */
final class AggregateStateProviderTest extends TestCase
{
    private const string ID = '01930000-0000-7000-8000-000000000001';

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_caller_is_refused_before_anything_is_resolved(): void
    {
        // the category is not even looked up: an unnamed caller must not learn which categories a
        // deployment declares, which is the very information the uniform 404 shields
        $this->expectException(AnonymousReadRefused::class);

        $this->provider(anonymous: false)->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
    }

    #[Test]
    public function the_refusal_names_the_aggregate_that_was_asked_for(): void
    {
        try {
            $this->provider(anonymous: false)->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
            self::fail('an unnamed caller must not reach the store');
        } catch (AnonymousReadRefused $e) {
            self::assertStringContainsString('aggregate.read', $e->getMessage());
            self::assertStringContainsString('opsthing-'.self::ID, $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function an_undeclared_category_and_an_unparsable_id_are_the_same_silence(): void
    {
        // both answer null and API Platform's native 404. Telling "malformed" from "absent" would
        // teach a prober which categories exist, which is the enumeration this read refuses
        self::assertNull($this->provider()->provide(new Get, ['category' => 'nothing-declared', 'id' => self::ID]));
        self::assertNull($this->provider()->provide(new Get, ['category' => 'opsthing', 'id' => 'not-a-uuid']));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_plain_aggregate_answers_existence_without_folding_anything(): void
    {
        // a class exposing no state can carry nothing out, so no replay runs: existence and version
        // come from one indexed head row. An HTTP GET must never fold a history to say `state: null`
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveAll');

        $answer = $this->provider(heads: ['plain-'.self::ID => 12], reader: $reader)
            ->provide(new Get, ['category' => 'plain', 'id' => self::ID]);

        self::assertNotNull($answer);
        self::assertSame(12, $answer->version);
        self::assertNull($answer->state);
    }

    #[Test]
    public function a_plain_aggregate_with_no_head_is_a_miss(): void
    {
        self::assertNull($this->provider()->provide(new Get, ['category' => 'plain', 'id' => self::ID]));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_existence_answer_leaves_an_audit_line(): void
    {
        // existence and version are exactly what the uniform 404 shields from the anonymous, so an
        // actor enumerating them must never be invisible in the module's own channel
        $recorder = new RecordingLog;

        $this->provider(heads: ['plain-'.self::ID => 12], audit: new OpsAuditLog($recorder))
            ->provide(new Get, ['category' => 'plain', 'id' => self::ID]);

        self::assertStringContainsString('served existence at version 12', $this->outcomes($recorder));
        // the SUBJECT is what an auditor greps: a line naming the category without its id, or the
        // id without its category, points at everything or at nothing
        self::assertSame('plain-'.self::ID, $this->subjects($recorder));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_history_past_the_ceiling_is_refused_on_the_head_alone(): void
    {
        // the head bounds the WORST replay and is one indexed row, so the ceiling is decided before
        // the fold rather than discovered inside it
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveAll');

        $this->expectException(AggregateFoldRefused::class);

        $this->provider(heads: ['opsthing-'.self::ID => AggregateStateProvider::MAX_FOLD_VERSIONS + 1], reader: $reader)
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
    }

    #[Test]
    public function a_history_exactly_at_the_ceiling_is_not_refused(): void
    {
        // the boundary is the refusal's whole meaning: a fold of exactly the ceiling is allowed, and
        // reading the comparison one step loose would refuse a history the surface promises to serve
        $answer = $this->provider(heads: ['opsthing-'.self::ID => AggregateStateProvider::MAX_FOLD_VERSIONS])
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);

        self::assertNull($answer, 'the fold ran and found no aggregate, which is a miss and not a refusal');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refused_fold_leaves_an_audit_line(): void
    {
        $recorder = new RecordingLog;

        try {
            $this->provider(heads: ['opsthing-'.self::ID => AggregateStateProvider::MAX_FOLD_VERSIONS + 1], audit: new OpsAuditLog($recorder))
                ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
            self::fail('a history past the ceiling must be refused');
        } catch (AggregateFoldRefused $e) {
            // the refusal travels INTO the line, verbatim: an outcome saying only "refused" leaves
            // an operator to guess between a ceiling and personal data
            self::assertSame('refused: '.$e->getMessage(), $this->outcomes($recorder));
            self::assertSame('opsthing-'.self::ID, $this->subjects($recorder));
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stream_carrying_personal_data_is_refused_before_the_replay_it_makes_pointless(): void
    {
        // a fold renders declared keys DECRYPTED, so this surface would serve the very artifact the
        // snapshot guard exists to prevent from ever reaching disk
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveAll');

        $recorder = new RecordingLog;

        try {
            $this->provider(heads: ['opsthing-'.self::ID => 3], personal: 'App\\CustomerNamed', reader: $reader, audit: new OpsAuditLog($recorder))
                ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
            self::fail('a stream carrying personal data must be refused');
        } catch (AggregateFoldRefused $e) {
            self::assertStringContainsString('App\\CustomerNamed', $e->getMessage());
            self::assertSame('refused: '.$e->getMessage(), $this->outcomes($recorder));
            self::assertSame('opsthing-'.self::ID, $this->subjects($recorder));
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_probe_that_fails_at_the_driver_crosses_as_the_boundary_type_this_surface_names(): void
    {
        // the guard probes with raw DBAL, as the module's read edges do; a driver exception escaping
        // here would be a type the caller's own throws clause never promised
        try {
            $this->provider(heads: ['opsthing-'.self::ID => 3], probeThrows: true)
                ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
            self::fail('a driver failure must cross as the contracted type');
        } catch (StorageFailure $e) {
            // the wrapped message names the STREAM it was probing; a failure that says only
            // "probe personal data" cannot be tied to the read that raised it
            self::assertStringContainsString('probe personal data on opsthing-'.self::ID, $e->getMessage());
        }
    }

    #[Test]
    public function a_failed_final_probe_never_publishes_or_audits_the_loaded_state(): void
    {
        $recorder = new RecordingLog;

        try {
            $this->provider(heads: ['opsthing-'.self::ID => 4], finalProbeThrows: true, snapshotAt: 4, audit: new OpsAuditLog($recorder))
                ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);
            self::fail('a failed final probe must refuse the loaded state');
        } catch (StorageFailure $e) {
            self::assertStringContainsString('probe personal data on opsthing-'.self::ID, $e->getMessage());
            self::assertSame('', $this->outcomes($recorder));
        }
    }

    #[Test]
    public function a_snapshotable_aggregate_answers_its_state_and_its_version(): void
    {
        $answer = $this->provider(heads: ['opsthing-'.self::ID => 4], snapshotAt: 4)
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);

        self::assertNotNull($answer);
        self::assertSame('opsthing', $answer->category);
        self::assertSame(self::ID, $answer->id);
        self::assertSame(4, $answer->version);
        self::assertIsArray($answer->state);
        self::assertSame('restored', $answer->state['label']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_served_state_leaves_an_audit_line_of_its_own(): void
    {
        // the payload-bearing read is the one that most owes a trace, and its line says the version
        // it served rather than merely that something was served
        $recorder = new RecordingLog;

        $this->provider(heads: ['opsthing-'.self::ID => 4], snapshotAt: 4, audit: new OpsAuditLog($recorder))
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID]);

        self::assertSame('served at version 4', $this->outcomes($recorder));
        self::assertSame('opsthing-'.self::ID, $this->subjects($recorder));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_historical_read_is_refused_before_its_version_is_read(): void
    {
        // actor first: a malformed version must not answer 400 to a caller the gate refuses
        $this->expectException(AnonymousReadRefused::class);

        $this->provider(anonymous: false)->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => 'abc']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_malformed_version_is_refused_rather_than_read_as_the_current_state(): void
    {
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveAll');
        $reader->expects($this->never())->method('retrieveByFilter');

        $this->expectException(MalformedQueryParameter::class);

        $this->provider(heads: ['opsthing-'.self::ID => 4], snapshotAt: 4, reader: $reader)
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => '2.0']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_historical_version_past_the_ceiling_is_refused_on_the_target_alone(): void
    {
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');
        $recorder = new RecordingLog;

        try {
            $this->provider(heads: ['opsthing-'.self::ID => 1], reader: $reader, audit: new OpsAuditLog($recorder))
                ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => (string) (HistoricalAggregateInspector::MAX_VERSIONS + 1)]]);
            self::fail('a target past the ceiling must be refused');
        } catch (AggregateFoldRefused $e) {
            self::assertSame('refused: '.$e->getMessage(), $this->outcomes($recorder));
            self::assertSame('opsthing-'.self::ID, $this->subjects($recorder));
        }
    }

    #[Test]
    public function a_historical_version_past_the_head_is_a_miss_without_a_fold(): void
    {
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        self::assertNull($this->provider(heads: ['opsthing-'.self::ID => 1], reader: $reader)
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => '2']]));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_historical_read_of_a_personal_stream_is_refused_before_the_fold(): void
    {
        // the probe covers the WHOLE stream: a personal event recorded after the target still
        // refuses, since the answer's absence of it cannot be proven without folding
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveByFilter');

        $this->expectException(AggregateFoldRefused::class);

        $this->provider(heads: ['opsthing-'.self::ID => 3], personal: 'App\\CustomerNamed', reader: $reader)
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => '1']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_historical_target_the_head_passed_over_an_empty_history_is_corruption(): void
    {
        // the head vouches for the version, so an empty read is a truncated stream, never a miss
        $this->expectException(CorruptAggregateHistory::class);

        $this->provider(heads: ['opsthing-'.self::ID => 3])
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => '2']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_plain_aggregate_answers_historical_existence_from_the_head_alone(): void
    {
        $reader = $this->createMock(StreamReader::class);
        $reader->expects($this->never())->method('retrieveAll');
        $reader->expects($this->never())->method('retrieveByFilter');
        $recorder = new RecordingLog;

        $answer = $this->provider(heads: ['plain-'.self::ID => 12], reader: $reader, audit: new OpsAuditLog($recorder))
            ->provide(new Get, ['category' => 'plain', 'id' => self::ID], ['filters' => ['version' => '5']]);

        self::assertNotNull($answer);
        self::assertSame(5, $answer->version);
        self::assertNull($answer->state);
        self::assertSame('served existence at historical version 5', $this->outcomes($recorder));
        self::assertNull($this->provider(heads: ['plain-'.self::ID => 4], reader: $reader)
            ->provide(new Get, ['category' => 'plain', 'id' => self::ID], ['filters' => ['version' => '5']]));
    }

    #[Test]
    public function historical_existence_at_the_head_itself_is_served_and_audited_under_its_stream(): void
    {
        $recorder = new RecordingLog;

        $answer = $this->provider(heads: ['plain-'.self::ID => 5], audit: new OpsAuditLog($recorder))
            ->provide(new Get, ['category' => 'plain', 'id' => self::ID], ['filters' => ['version' => '5']]);

        self::assertNotNull($answer);
        self::assertSame(5, $answer->version);
        self::assertSame('plain-'.self::ID, $this->subjects($recorder));
    }

    #[Test]
    public function a_historical_target_at_the_ceiling_itself_is_not_refused(): void
    {
        // the last foldable version passes the ceiling and meets the head, here a short one: a miss
        self::assertNull($this->provider(heads: ['opsthing-'.self::ID => 1])
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => (string) HistoricalAggregateInspector::MAX_VERSIONS]]));
    }

    #[Test]
    public function a_historical_target_at_the_head_itself_reaches_the_fold(): void
    {
        // the head vouches for its own version, so an empty read there is a truncated stream
        $this->expectException(CorruptAggregateHistory::class);

        $this->provider(heads: ['opsthing-'.self::ID => 3])
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['version' => '3']]);
    }

    #[Test]
    public function a_request_without_a_version_keeps_the_current_answer(): void
    {
        $answer = $this->provider(heads: ['opsthing-'.self::ID => 4], snapshotAt: 4)
            ->provide(new Get, ['category' => 'opsthing', 'id' => self::ID], ['filters' => ['limit' => '2']]);

        self::assertNotNull($answer);
        self::assertSame(4, $answer->version);
    }

    private function subjects(RecordingLog $recorder): string
    {
        return implode("\n", array_map(
            static fn (array $record): string => (string) ($record['context']['subject'] ?? ''),
            $recorder->records,
        ));
    }

    private function outcomes(RecordingLog $recorder): string
    {
        return implode("\n", array_map(
            static fn (array $record): string => (string) ($record['context']['outcome'] ?? ''),
            $recorder->records,
        ));
    }

    /**
     * @param  array<string, int>  $heads
     */
    private function provider(
        bool $anonymous = true,
        array $heads = [],
        ?string $personal = null,
        bool $probeThrows = false,
        bool $finalProbeThrows = false,
        ?int $snapshotAt = null,
        ?StreamReader $reader = null,
        ?OpsAuditLog $audit = null,
    ): AggregateStateProvider {
        $log = $audit ?? new OpsAuditLog(new NullLogger);
        $gate = new OpsActorGate($anonymous ? new OpsAuditLog(new NullLogger) : $log, null, allowAnonymousReads: $anonymous);
        $streamReader = $reader ?? $this->emptyReader();

        // @phpstan-ignore argument.type (the plain lane is a fixture FQCN; the catalog never loads it)
        $catalog = new AggregateCatalog([
            OpsSnapshotAggregate::class => ['id' => OpsAggregateId::class, 'category' => 'opsthing'],
            'App\\PlainThing' => ['id' => OpsAggregateId::class, 'category' => 'plain'],
        ]);

        // ONE head store for both, as a deployment has: a snapshot may neither outrun nor outlive
        // its stream, so a repository reading a different head than the provider would restore from
        // a snapshot the coherence rule exists to reject
        $headStore = $this->heads($heads);

        return new AggregateStateProvider(
            $catalog,
            $this->manager($streamReader, $snapshotAt, $headStore),
            $headStore,
            $this->guard($personal, $probeThrows, $finalProbeThrows),
            $gate,
            $log,
        );
    }

    private function manager(StreamReader $reader, ?int $snapshotAt, StreamHeadStore $heads): AggregateRepositoryManager
    {
        $snapshots = $this->createStub(SnapshotStore::class);

        if ($snapshotAt !== null) {
            $snapshots->method('load')->willReturn(new Snapshot(
                stream: 'opsthing-'.self::ID,
                aggregateType: OpsSnapshotAggregate::class,
                version: $snapshotAt,
                state: ['label' => 'restored'],
                createdAt: PointInTime::from('2026-08-23T10:00:00.000000+00:00'),
            ));
        }

        return new AggregateRepositoryManager(
            [OpsSnapshotAggregate::class => ['id' => OpsAggregateId::class, 'category' => 'opsthing']],
            $reader,
            $this->createStub(DecisionAppend::class),
            $this->createStub(MessageEnricher::class),
            $snapshots,
            $heads,
        );
    }

    private function emptyReader(): StreamReader
    {
        $reader = $this->createStub(StreamReader::class);
        $reader->method('retrieveAll')->willReturnCallback(static function (): Generator {
            yield from [];
        });
        $reader->method('retrieveByFilter')->willReturnCallback(static function (): Generator {
            yield from [];
        });

        return $reader;
    }

    /**
     * @param  array<string, int>  $heads
     */
    private function heads(array $heads): StreamHeadStore
    {
        $store = $this->createStub(StreamHeadStore::class);
        $store->method('lastVersion')->willReturnCallback(static fn (string $stream): int => $heads[$stream] ?? 0);

        return $store;
    }

    private function guard(?string $personal, bool $throws, bool $finalProbeThrows = false): PersonalDataSnapshotGuard
    {
        $connection = $this->createStub(Connection::class);

        if ($finalProbeThrows) {
            $calls = 0;
            $connection->method('fetchOne')->willReturnCallback(static function () use (&$calls): false {
                if (++$calls > 1) {
                    throw new class('the final probe is unreachable') extends RuntimeException implements DbalException {};
                }

                return false;
            });
        } elseif ($throws) {
            // the boundary type is an INTERFACE, so the double is one of its own, the idiom the
            // chronicler fixtures already use for a driver failure
            $connection->method('fetchOne')->willThrowException(new class('the probe is unreachable') extends RuntimeException implements DbalException {});
        } else {
            $connection->method('fetchOne')->willReturn($personal ?? false);
        }

        $mapper = $this->createStub(EventTypeMapper::class);
        $mapper->method('storedTypesOf')->willReturn(['App\\CustomerNamed']);

        // an empty map short-circuits the probe entirely, which is the clean lane; a marked class is
        // what makes the probe actually run
        return new PersonalDataSnapshotGuard(
            $connection,
            $mapper,
            // @phpstan-ignore argument.type (a fixture FQCN; the guard never loads the marked class)
            $throws || $finalProbeThrows || $personal !== null
                ? ['App\\CustomerNamed' => ['subject' => 'customer', 'keys' => ['name'], 'fallbacks' => []]]
                : [],
        );
    }
}

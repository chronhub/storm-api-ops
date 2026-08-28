<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\SagaCancelRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\Error\SagaRedriveRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\State\SagaCancelProcessor;
use Storm\ApiOps\State\SagaPauseProcessor;
use Storm\ApiOps\State\SagaRedriveProcessor;
use Storm\ApiOps\State\SagasProvider;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Store\Inspection\ChildSnapshot;
use Storm\Saga\Store\Inspection\OutboxSnapshot;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Store\Inspection\TimerSnapshot;
use Storm\Saga\Workflow\CompensationRecord;

use function array_flip;
use function array_intersect_key;
use function array_map;

/**
 * One saga instance in introspection, the HTTP twin of `storm:saga:inspect`: scalar state plus
 * its armed timers, the commands it issued, and the compensation log, THE forensic record of a
 * halted saga. A collection by correlation on purpose: the gateway preserves the rare legacy
 * cross-saga correlation as one snapshot per workflow type, never collapsed.
 *
 * The nested records carry the snapshots' own machine shape with snake_case wire keys, the same
 * contract the console serves with `--json`, so a script reads one format wherever it looks.
 *
 * Four POST verbs ride the same resource. `cancel` is the stop button: halt the saga where it sits.
 * `redrive` is the repair: send one dead-lettered command back into flight and leave the saga alone.
 * Reach for the repair FIRST, since a command usually dies of a cause that is not the saga's.
 * `pause` and `resume` are the reversible pair: freeze this instance so no NEXT step executes and
 * no pending command leaves its outbox; a step already in flight completes, the status stays
 * untouched; then lift the freeze.
 *
 * Authorization stays app configuration through a method-scoped access_control on the ops zone
 * matching `^/(api/)?_storm`, where the `(api/)?` covers the bridge's `/api` mount; beneath it,
 * {@see OpsActorGate} refuses an anonymous mutation outright, a 403. Every verb refuses with a
 * 409 that names what declined: one of four read-back causes for the cancel, one of four guards for
 * the redrive, nothing to freeze or nothing to lift for the pair. The cancel and the redrive carry
 * the same `force` contract, which owns a risk rather than skipping a check.
 */
#[ApiResource(
    shortName: 'StormSaga',
    operations: [
        new GetCollection(
            uriTemplate: '/_storm/sagas/{correlationId}',
            uriVariables: ['correlationId'],
            paginationEnabled: false,
            provider: SagasProvider::class,
            parameters: [
                'type' => new QueryParameter(schema: ['type' => 'string'], description: 'Narrow to one workflow type when a legacy correlation is shared cross-saga.'),
            ],
        ),
        new Post(
            uriTemplate: '/_storm/sagas/{correlationId}/cancel',
            uriVariables: ['correlationId'],
            status: 200,
            input: CancelSagaInput::class,
            processor: SagaCancelProcessor::class,
        ),
        new Post(
            uriTemplate: '/_storm/sagas/{correlationId}/redrive',
            uriVariables: ['correlationId'],
            status: 200,
            input: RedriveSagaInput::class,
            processor: SagaRedriveProcessor::class,
        ),
        new Post(
            uriTemplate: '/_storm/sagas/{correlationId}/pause',
            uriVariables: ['correlationId'],
            status: 200,
            input: PauseSagaInput::class,
            processor: SagaPauseProcessor::class,
            extraProperties: ['storm_ops_action' => 'pause'],
        ),
        new Post(
            uriTemplate: '/_storm/sagas/{correlationId}/resume',
            uriVariables: ['correlationId'],
            status: 200,
            input: ResumeSagaInput::class,
            processor: SagaPauseProcessor::class,
            extraProperties: ['storm_ops_action' => 'resume'],
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    exceptionToStatus: [
        AnonymousMutationRefused::class => 403,
        AnonymousReadRefused::class => 403,
        WorkflowNotFound::class => 404,
        StaleWorkflowInstance::class => 409,
        SagaCancelRefused::class => 409,
        SagaCommandNotFound::class => 404,
        SagaRedriveRefused::class => 409,
        SagaPauseRefused::class => 409,
    ],
)]
final readonly class SagaResource
{
    /**
     * The nested wire keys this surface consciously serves, one allowlist per snapshot shape: the
     * snapshots belong to the Saga module, and a key IT adds must be ADMITTED here to reach this
     * public HTTP surface, never leak through a pass-through `toArray()`; the resource test holds
     * each list against the snapshot's real keys, so an upstream addition fails a build instead of
     * shipping silently.
     */
    public const array COMPENSATION_KEYS = ['step', 'status', 'confirmed', 'degraded', 'reason', 'at'];

    public const array TIMER_KEYS = ['id', 'kind', 'state_key', 'fire_at', 'claimed_at', 'attempts', 'parked_at', 'last_error'];

    public const array OUTBOX_KEYS = ['status', 'command', 'bus', 'attempts', 'created_at', 'last_error', 'issued_from_state', 'issued_at_version', 'generation', 'message_id', 'evidence', 'effect_group'];

    public const array CHILD_KEYS = ['workflow_type', 'correlation_id', 'status'];

    /**
     * @param  array<string, array{n: int, since: string|null}>  $retries  state key to visit ledger: attempt
     *                                                                     count plus the visit window's opening
     *                                                                     instant, null for a legacy row
     * @param  list<array<string, mixed>>  $compensations  the rollback log, in completion order
     * @param  list<array<string, mixed>>  $timers
     * @param  list<array<string, mixed>>  $outbox
     * @param  list<array<string, mixed>>  $children  every child row, any status: the tree walk's next hop
     */
    public function __construct(
        public string $workflowType,
        public string $stateKey,
        public string $status,
        public int $version,
        public ?string $startedAt,
        public array $retries,
        public array $compensations,
        public array $timers,
        public array $outbox,
        public ?string $parentWorkflowType = null,
        public ?string $parentCorrelationId = null,
        public ?string $rootCorrelationId = null,
        public array $children = [],
        // @infection-ignore-all; equivalent: the named factory below is the only construction path
        // in this package and always passes this, so the default stands for a consumer building the
        // resource by hand and no test can observe it
        public int $retimes = 0,
        /** The operator freeze stamp; null when executable. A paused saga must SAY so wherever it is read. */
        public ?string $pausedAt = null,
        public ?string $pausedReason = null,
        /**
         * Whether the whole workflow TYPE is frozen, which no instance stamp carries: that freeze
         * lives in `workflow_pauses` alone and gates births as well as steps. A saga held by it must
         * SAY so, exactly like one held by its own stamp, and lifting one does not lift the other.
         */
        public bool $typePaused = false,
        /** The staleness clock, the first question a halted saga raises. */
        public ?string $updatedAt = null,
        /** The run counter the sibling history endpoint asks the caller to supply; readable HERE. */
        // @infection-ignore-all; equivalent: the named factory below is the only construction path in this package and always passes this, so the default stands for a consumer building the resource by hand and no test can observe it
        public int $generation = 0,
        // @infection-ignore-all; equivalent: the named factory below is the only construction path in this package and always passes this, so the default stands for a consumer building the resource by hand and no test can observe it
        public int $definitionVersion = 1,
        // @infection-ignore-all; equivalent: the named factory below is the only construction path in this package and always passes this, so the default stands for a consumer building the resource by hand and no test can observe it
        public int $stateVersion = 1,
        // @infection-ignore-all; equivalent: the named factory below is the only construction path in this package and always passes this, so the default stands for a consumer building the resource by hand and no test can observe it
        public int $retryTotal = 0,
        public ?string $waivedAt = null,
        /**
         * The allowlist-filtered window onto app state the Saga module designed for exactly this
         * surface; the console twin serves it, so this channel does too.
         *
         * @var array<string, mixed>
         */
        public array $exposed = [],
    ) {}

    public static function fromSnapshot(SagaSnapshot $saga): self
    {
        return new self(
            workflowType: $saga->workflowType,
            stateKey: $saga->stateKey,
            status: $saga->status,
            version: $saga->version,
            startedAt: $saga->startedAt,
            retries: $saga->retries,
            compensations: array_map(static fn (CompensationRecord $c): array => self::pick($c->toArray(), self::COMPENSATION_KEYS), $saga->compensations),
            timers: array_map(static fn (TimerSnapshot $t): array => self::pick($t->toArray(), self::TIMER_KEYS), $saga->timers),
            outbox: array_map(static fn (OutboxSnapshot $o): array => self::pick($o->toArray(), self::OUTBOX_KEYS), $saga->outbox),
            parentWorkflowType: $saga->parentWorkflowType,
            parentCorrelationId: $saga->parentCorrelationId,
            rootCorrelationId: $saga->rootCorrelationId,
            children: array_map(static fn (ChildSnapshot $c): array => self::pick($c->toArray(), self::CHILD_KEYS), $saga->children),
            retimes: $saga->retimes,
            pausedAt: $saga->pausedAt,
            pausedReason: $saga->pausedReason,
            typePaused: $saga->typePaused,
            updatedAt: $saga->updatedAt,
            generation: $saga->generation,
            definitionVersion: $saga->definitionVersion,
            stateVersion: $saga->stateVersion,
            retryTotal: $saga->retryTotal,
            waivedAt: $saga->waivedAt,
            exposed: $saga->exposed,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function pick(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }
}

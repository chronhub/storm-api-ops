<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\ProjectionNotFound;
use Storm\ApiOps\Error\ProjectionTransitionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\State\ProjectionActionProcessor;
use Storm\ApiOps\State\ProjectionsProvider;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionNotFailed;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;

/**
 * One projection as the ops surface sees it: the HTTP twin of `storm:projection:status`, the same
 * row, lag and liveness reads, structured instead of table cells. Status and its pause horizon
 * become two fields, the lease becomes owner, live truth and horizon, and the producer's output
 * becomes stream or prefix. The row keeps `lease_owner` after a worker crash, so `leaseLive` carries
 * the SQL-clock answer.
 *
 * Every key the row serves reaches this resource. The console renders the wide fields only for a
 * NAMED projection, a table being unable to pay their width across a sweep; a structured twin has
 * no such cost, so it carries them on both the item and the collection.
 *
 * The collection is unwindowed on purpose: projections are a declared, code-bounded population,
 * nothing like the streams' directory.
 *
 * The status controls ride the same resource as POST verbs, the destructive actions this
 * package's opt-in is about. Authorization stays app configuration, the ops-zone showcase pattern:
 * a method-scoped access_control line matching `^/(api/)?_storm` on POST to `ROLE_ADMIN` is the
 * whole gesture, and the framework hard-codes no role. The `(api/)?` matters: the bridge mounts
 * these resources under `/api`, so the real path is `/api/_storm/*`. Beneath the firewall,
 * {@see OpsActorGate} refuses an anonymous mutation outright, a 403. A refused transition
 * answers 409 with the state machine's own words; a success answers the projection's fresh truth.
 */
#[ApiResource(
    shortName: 'StormProjection',
    operations: [
        new Get(
            uriTemplate: '/_storm/projections/{name}',
            uriVariables: ['name'],
            provider: ProjectionsProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/_storm/projections',
            paginationEnabled: false,
            provider: ProjectionsProvider::class,
        ),
        new Post(
            uriTemplate: '/_storm/projections/{name}/pause',
            uriVariables: ['name'],
            status: 200,
            input: PauseProjectionInput::class,
            processor: ProjectionActionProcessor::class,
            extraProperties: ['storm_ops_action' => 'pause'],
        ),
        new Post(
            uriTemplate: '/_storm/projections/{name}/resume',
            uriVariables: ['name'],
            status: 200,
            input: false,
            processor: ProjectionActionProcessor::class,
            extraProperties: ['storm_ops_action' => 'resume'],
        ),
        new Post(
            uriTemplate: '/_storm/projections/{name}/stop',
            uriVariables: ['name'],
            status: 200,
            input: false,
            processor: ProjectionActionProcessor::class,
            extraProperties: ['storm_ops_action' => 'stop'],
        ),
        new Post(
            uriTemplate: '/_storm/projections/{name}/retry',
            uriVariables: ['name'],
            status: 200,
            input: false,
            processor: ProjectionActionProcessor::class,
            extraProperties: ['storm_ops_action' => 'retry'],
        ),
        new Post(
            uriTemplate: '/_storm/projections/{name}/reset',
            uriVariables: ['name'],
            status: 200,
            input: false,
            processor: ProjectionActionProcessor::class,
            extraProperties: ['storm_ops_action' => 'reset'],
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    exceptionToStatus: [
        AnonymousMutationRefused::class => 403,
        AnonymousReadRefused::class => 403,
        ProjectionNotFound::class => 404,
        UnknownProjection::class => 404,
        ProjectionTransitionRefused::class => 409,
        ProjectionBusy::class => 409,
        ProjectionNotFailed::class => 409,
        UnsupportedProjection::class => 422,
    ],
)]
final readonly class ProjectionResource
{
    public function __construct(
        public string $name,
        public string $status,
        public ?string $pauseUntil,
        public int $position,
        public int $lag,
        public bool $atHead,
        public string $mode,
        /** @var list<string> the declared category selection; empty means every category */
        public array $categories,
        /** @var list<string> the declared event-type selection; empty means every type */
        public array $eventClasses,
        /** The stream this projection folds when it consumes a derived one; null when it scans the store. */
        public ?string $sourceStream,
        /**
         * The revision of the source stream this projection folded under.
         *
         * One of the three grounds a run stands down on, and the stand-down names this surface as
         * where to look: a twin that answers without it sends the operator back where they came from.
         */
        public int $sourceRevision,
        public ?string $leaseOwner,
        public ?bool $leaseLive,
        /** When the lease lapses or lapsed; the horizon `leaseLive` reduces to a yes or no. */
        public ?string $leaseUntil,
        public ?string $heartbeatAt,
        public ?string $targetStream,
        public ?string $targetPrefix,
        public int $generation,
        public ?string $failedAt,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {}
}

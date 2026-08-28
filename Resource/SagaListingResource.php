<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Storm\Saga\Store\Inspection\SagaSummarySnapshot;

/**
 * One saga inside the {@see SagaListingPageResource} envelope: the scalars an operator scans when
 * hunting an incident, no satellites, since a listing carrying each saga's timers and outbox would
 * cost a query per row over an unbounded population.
 *
 * `/_storm/sagas/{correlationId}` is the next hop, and `correlationId` here is what to feed it.
 */
final readonly class SagaListingResource
{
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $stateKey,
        public string $status,
        public int $version,
        public int $generation,
        public int $definitionVersion,
        public int $retryTotal,
        public ?string $startedAt,
        public ?string $updatedAt,
        public ?string $waivedAt,
        public ?string $parentCorrelationId,
        /** The operator freeze stamp; null when executable. A paused saga must SAY so in a listing. */
        public ?string $pausedAt = null,
        /**
         * Whether the whole workflow TYPE is frozen, which no instance stamp carries: that freeze
         * lives in `workflow_pauses` alone and gates births as well as steps. A saga held by it
         * must SAY so, exactly like one held by its own stamp.
         */
        public bool $typePaused = false,
    ) {}

    /**
     * The listing row carried verbatim, field for field, in this surface's own idiom: scalars ride
     * camelCase here like {@see SagaResource}'s, where the console's `--json` serves the snapshot's
     * snake_case. The shared machine contract is the snapshots' `toArray()`, which the nested records
     * of the by-correlation resource carry; a listing row has no nested records to carry it.
     */
    public static function fromSnapshot(SagaSummarySnapshot $snapshot): self
    {
        return new self(
            workflowType: $snapshot->workflowType,
            correlationId: $snapshot->correlationId,
            stateKey: $snapshot->stateKey,
            status: $snapshot->status,
            version: $snapshot->version,
            generation: $snapshot->generation,
            definitionVersion: $snapshot->definitionVersion,
            retryTotal: $snapshot->retryTotal,
            startedAt: $snapshot->startedAt,
            updatedAt: $snapshot->updatedAt,
            waivedAt: $snapshot->waivedAt,
            parentCorrelationId: $snapshot->parentCorrelationId,
            pausedAt: $snapshot->pausedAt,
            typePaused: $snapshot->typePaused,
        );
    }
}

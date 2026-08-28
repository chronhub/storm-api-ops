<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Storm\Telemetry\History\WorkflowHistoryRecord;

/**
 * One recorded `Saga*` announcement inside the {@see SagaHistoryResource} envelope: the read-side
 * {@see \Storm\Telemetry\History\WorkflowHistoryRecord}, carried verbatim onto the wire.
 *
 * On what the payload carries: no `Saga*` event holds `vars` or `context`, so this does NOT reopen
 * the business-bag exposure the listing and the snapshot both refuse. It is engine vocabulary:
 * state names, counters, event class names. Two fields are app-ORIGINATED all the same and worth
 * naming: `CompensationFailed.error` is an app exception message, and `SagaCancelled.reason` is
 * free text an operator typed. Both are the same class of content as the `last_error` this surface
 * already serves on timers and outbox rows, so they change no exposure regime, though an app that
 * puts customer data in an exception message will find it here.
 */
final readonly class SagaHistoryRecordResource
{
    /**
     * @param  int  $generation  the run that announced; 0 for a pre-generation row or a skip
     *                           announced without the claimed row in hand
     * @param  array<string, mixed>  $payload  the event's remaining public properties
     * @param  string  $occurredAt  the saga's own announce time; empty on a pre-identity row
     * @param  string  $recordedAt  when the row landed; under the async sink, the gap to `occurredAt` IS the observability lag
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $eventType,
        public array $payload,
        public string $eventId,
        public string $occurredAt,
        public string $recordedAt,
    ) {}

    public static function fromRecord(WorkflowHistoryRecord $record): self
    {
        return new self(
            workflowType: $record->workflowType,
            correlationId: $record->correlationId,
            generation: $record->generation,
            eventType: $record->eventType,
            payload: $record->payload,
            eventId: $record->eventId,
            occurredAt: $record->occurredAt,
            recordedAt: $record->recordedAt,
        );
    }
}

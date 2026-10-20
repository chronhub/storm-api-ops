<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Storm\Telemetry\History\WorkflowHistoryRecord;

/**
 * One recorded `Saga*` announcement inside the {@see SagaHistoryResource} envelope: the read-side
 * {@see \Storm\Telemetry\History\WorkflowHistoryRecord}, carried verbatim onto the wire.
 *
 * No `Saga*` event holds `vars` or `context`, so the payload does not reopen the business-bag exposure
 * the listing and the snapshot both refuse: it is engine vocabulary, state names, counters and event
 * class names. Two fields still originate in the app and are worth naming:
 *
 * - `CompensationFailed.error` is the text an undo returned, verbatim; for an undo that threw, it is the
 *   class and code of each level of the exception chain, a Storm refusal keeping its message, and the
 *   app's message goes to the logs.
 * - `SagaCancelled.reason` is free text an operator typed, verbatim.
 *
 * An app that puts customer data in a returned failure or an operator who types it in a reason will
 * find it here.
 */
final readonly class SagaHistoryRecordResource
{
    /**
     * @param  int  $generation  the run that announced; 0 for a pre-generation row or a skip
     *                           announced without the claimed row in hand
     * @param  array<string, mixed>  $payload  the event's remaining public properties
     * @param  string  $occurredAt  the saga's own announce time; empty on a pre-identity row
     * @param  string  $recordedAt  when the row landed; under the async sink, the gap to `occurredAt` is the
     *                              observability lag
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

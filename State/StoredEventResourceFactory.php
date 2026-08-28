<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use Storm\ApiOps\Resource\StoredEventResource;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Message\Header;

use function is_string;

/**
 * A stored row as the hydrated, veiled resource every event window serves.
 *
 * It exists because more than one window answers with the same rows, the stream and aggregate
 * histories and the correlation trace, and a mapping copied per window is free to drift by one
 * character: a veil applied in one place and forgotten in another would serve decrypted values
 * from one URL and not the next, with both answers looking correct.
 */
final readonly class StoredEventResourceFactory
{
    public function __construct(
        private PersonalDataVeil $veil = new PersonalDataVeil,
    ) {}

    /**
     * The stored type wins when the row carries one; a row without it falls back to the hydrated
     * event's class, which is what the reader just resolved the alias to.
     *
     * @throws NotADomainEvent when the stored row wraps a non-event, a corrupt read
     */
    public function fromRecord(EventRecord $record): StoredEventResource
    {
        $message = $record->message;
        $type = $message->header(Header::MessageType);

        return new StoredEventResource(
            position: $record->position->toOrdinal(),
            stream: $message->streamName() ?? '',
            type: is_string($type) ? $type : $record->event()::class,
            payload: $this->veil->veil($record),
            headers: $message->headers(),
            recordedAt: $record->recordedAt->toString(),
        );
    }
}

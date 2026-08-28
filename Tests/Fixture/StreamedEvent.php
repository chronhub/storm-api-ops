<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;

/**
 * A minimal domain event for the read surfaces, which refuse anything else.
 *
 * Local to this package on purpose: a package's `Tests/` is export-ignored, so borrowing another
 * module's fixture would compile here and vanish from a standalone install.
 */
final class StreamedEvent implements DomainEvent
{
    public function __construct(
        public string $what = 'something',
    ) {}

    public function aggregateId(): string
    {
        return 'account-1';
    }

    public function toPayload(): array
    {
        return ['what' => $this->what];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['what']);
    }
}

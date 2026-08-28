<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\OutboxFailedResource;
use Storm\Chronicler\Outbox\FailedOutboxMessage;
use Storm\Chronicler\Outbox\OutboxDeadLetter;

/**
 * The outbox dead-letter as an ops collection, the HTTP twin of `storm:outbox:failed`.
 *
 * The read is bounded in SQL rather than sliced after the fact, so a caller cannot talk the
 * surface into loading a dead-letter that grew unattended. It carries no payload, so it leaves no
 * audit trace either: the module's audit channel exists for reads that serve stored content, and a
 * cause with an attempt count is not that.
 *
 * @implements ProviderInterface<OutboxFailedResource>
 */
final readonly class OutboxFailedProvider implements ProviderInterface
{
    public function __construct(
        private OutboxDeadLetter $deadLetter,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<OutboxFailedResource>
     *
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     * @throws Exception on a DBAL read failure
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->gate->assertOwnedIdentityForRead('outbox.read', '*');

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        $page = $this->deadLetter->listFailedPage(
            PageWindow::afterPosition($filters),
            PageWindow::limit($filters),
        );

        return array_map(static fn (FailedOutboxMessage $row): OutboxFailedResource => new OutboxFailedResource(
            id: $row->id,
            position: $row->position,
            type: $row->type,
            attempts: $row->attempts,
            lastError: $row->lastError,
            failedAt: $row->failedAt,
        ), $page);
    }
}

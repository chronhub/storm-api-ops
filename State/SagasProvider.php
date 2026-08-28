<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\SagaResource;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Throwable;

use function array_map;
use function is_string;

/**
 * Saga instances by correlation, the HTTP twin of `storm:saga:inspect`, over the same
 * read-only gateway. An unknown correlation answers the empty collection: the console pins
 * FAILURE there because exit codes are a pipeline's only voice, but an HTTP collection shows
 * its emptiness; each channel's own idiom.
 *
 * @implements ProviderInterface<SagaResource>
 */
final readonly class SagasProvider implements ProviderInterface
{
    public function __construct(
        private SagaInspectionGateway $gateway,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<SagaResource>
     *
     * @throws JsonException on a retries- or compensations-bag decode failure
     * @throws Exception on a raw DBAL read failure
     * @throws Throwable rethrown from the gateway's read-only transaction wrapper
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->gate->assertOwnedIdentityForRead('saga.read', (string) ($uriVariables['correlationId'] ?? '*'));

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];
        $type = $filters['type'] ?? null;

        $sagas = $this->gateway->inspect(
            (string) ($uriVariables['correlationId'] ?? ''),
            is_string($type) && $type !== '' ? $type : null,
        );

        return array_map(SagaResource::fromSnapshot(...), $sagas);
    }
}

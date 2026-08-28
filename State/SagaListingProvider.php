<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\SagaListingPageResource;
use Storm\ApiOps\Resource\SagaListingResource;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Support\Console\PositiveIntOption;

use function array_map;
use function get_debug_type;
use function is_scalar;
use function is_string;

/**
 * The filtered saga directory, the HTTP twin of `storm:saga:list`, over the same gateway the console
 * renders. The window is the gateway's own cap, so no caller can talk this surface into an unbounded
 * scan, and the truncation flag travels with the rows: the gateway reads one row past the cap, so a
 * full page is distinguishable from a finished population, and there is no cursor to page past the
 * wall.
 *
 * An unknown status never reaches this provider over HTTP: the parameter schema declares the enum,
 * which API Platform compiles into a real constraint, so a typo'd status is a 422 at the edge, the
 * same refusal the console makes for its own reasons. The `tryFrom` fallback below is therefore a
 * dead net over HTTP and stands only for a caller invoking the provider directly.
 *
 * @implements ProviderInterface<SagaListingPageResource>
 */
final readonly class SagaListingProvider implements ProviderInterface
{
    public function __construct(
        private SagaInspectionGateway $gateway,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     *
     * @throws MalformedQueryParameter when `limit` or `idle_for` is present and is not a positive
     *                                 integer, a 422; reading either loosely serves an answer the
     *                                 caller never asked for
     * @throws Exception on a raw DBAL read failure
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SagaListingPageResource
    {
        $this->gate->assertOwnedIdentityForRead('sagas.read', '*');

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        $idle = $filters['idle_for'] ?? null;
        $waived = $filters['waived'] ?? null;
        $limit = $filters['limit'] ?? null;

        // both integer filters are read through the parser the console twin uses, so one rule answers
        // for both channels: integral in FORM, not merely numeric. The declared schema compiles a
        // range that refuses what is no number at all, and passes a fraction; cast, `idle_for`
        // loosens the very guard it names and serves sagas the caller asked to leave out, while
        // `limit` echoes a window nobody requested back as though it had been.
        $window = $limit === null ? SagaInspectionGateway::DEFAULT_LIMIT : PositiveIntOption::parse($limit);

        if ($window === null) {
            throw MalformedQueryParameter::expectingAPositiveInteger('limit', is_scalar($limit) ? (string) $limit : get_debug_type($limit));
        }

        $idleSeconds = $idle === null ? null : PositiveIntOption::parse($idle);

        if ($idle !== null && $idleSeconds === null) {
            throw MalformedQueryParameter::expectingAPositiveInteger('idle_for', is_scalar($idle) ? (string) $idle : get_debug_type($idle));
        }

        $listing = $this->gateway->list(
            type: is_string($type) && $type !== '' ? $type : null,
            status: is_string($status) ? WorkflowStatus::tryFrom($status) : null,
            idleForSeconds: $idleSeconds,
            waivedOnly: $waived === true || $waived === 'true' || $waived === '1',
            limit: $window,
        );

        return new SagaListingPageResource(
            sagas: array_map(SagaListingResource::fromSnapshot(...), $listing->sagas),
            truncated: $listing->truncated,
            limit: $listing->limit,
        );
    }
}

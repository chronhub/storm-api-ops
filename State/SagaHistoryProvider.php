<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\Error\OperatorPermissionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\SagaHistoryRecordResource;
use Storm\ApiOps\Resource\SagaHistoryResource;
use Storm\Support\Console\PositiveIntOption;
use Storm\Telemetry\History\GenerationOutOfRange;
use Storm\Telemetry\History\WorkflowHistoryStore;
use Throwable;

use function array_map;
use function get_debug_type;
use function is_scalar;
use function is_string;

/**
 * One saga's recorded timeline, the HTTP twin of `storm:telemetry:history`, over the same store the
 * console renders.
 *
 * The window is the store's own cap, so no caller can talk this surface into scanning a retention
 * table. The store's whole answer crosses the wire, not just its rows: `truncated`, `limit` and the
 * availability fact ride the envelope, so a capped window never reads as a complete timeline and an
 * empty one says which kind of empty it is, the same contract the console twin renders.
 *
 * @implements ProviderInterface<SagaHistoryResource>
 */
final readonly class SagaHistoryProvider implements ProviderInterface
{
    public function __construct(
        private WorkflowHistoryStore $history,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws MalformedQueryParameter when `generation` or `limit` is present and is not a positive
     *                                 integer, a 422; reading either loosely serves an answer the
     *                                 caller never asked for
     * @throws GenerationOutOfRange when `generation` is an integer the history column cannot store,
     *                              the store's own refusal, a 422
     * @throws JsonException when a stored payload is not decodable jsonb
     * @throws Exception on a raw DBAL read failure
     * @throws OperatorPermissionRefused when the application does not grant operator access
     * @throws Throwable when an application identity or permission backend cannot answer
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SagaHistoryResource
    {
        $this->gate->assertOwnedIdentityForRead('saga.history.read', OpsRoute::string($uriVariables, 'correlationId', '*'));

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        $type = $filters['type'] ?? null;
        $limit = $filters['limit'] ?? null;
        $generation = $filters['generation'] ?? null;
        $correlationId = OpsRoute::string($uriVariables, 'correlationId');

        // an unreadable narrowing parameter is refused, never dropped: dropped, it would aggregate every
        // run of a reused correlation, and with no generation in the envelope the caller could not tell
        // that widened answer from the one asked for. The schema's `minimum` refuses an out-of-range
        // integer, but its `type` lets a non-integer through; the parser checks the lower bound too, so
        // neither half depends on the other, and refuses what an int cannot hold, since no `maximum` is
        // declared and a cast saturating at `PHP_INT_MAX` would reach the column as a value it cannot
        // store.
        $run = $generation === null ? null : PositiveIntOption::parse($generation);

        if ($generation !== null && $run === null) {
            // equivalent: `sprintf` renders a scalar identically with or
            // without the cast; the cast serves the analyzer, the branch beside it carries the type
            // @infection-ignore-all
            throw MalformedQueryParameter::expectingAPositiveInteger('generation', is_scalar($generation) ? (string) $generation : get_debug_type($generation));
        }

        // the window is read through the parser the console twin uses, so one rule answers for both
        // channels: integral in form, not merely numeric. is_numeric() accepts '1.5', and a cast
        // would serve a window the caller never asked for while the envelope echoes the narrowed
        // number back as though it had been requested, which is the reading that hides truncation.
        $window = $limit === null ? WorkflowHistoryStore::DEFAULT_LIMIT : PositiveIntOption::parse($limit);

        if ($window === null) {
            // equivalent: same as the generation branch above, the cast is
            // for the analyzer and `sprintf` renders the scalar the same either way
            // @infection-ignore-all
            throw MalformedQueryParameter::expectingAPositiveInteger('limit', is_scalar($limit) ? (string) $limit : get_debug_type($limit));
        }

        $timeline = $this->history->read(
            $correlationId,
            is_string($type) && $type !== '' ? $type : null,
            $window,
            $run,
        );

        return new SagaHistoryResource(
            correlationId: $correlationId,
            records: array_map(SagaHistoryRecordResource::fromRecord(...), $timeline->records),
            truncated: $timeline->truncated,
            limit: $timeline->limit,
            availability: $timeline->availability->value,
        );
    }
}

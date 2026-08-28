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
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\SagaHistoryRecordResource;
use Storm\ApiOps\Resource\SagaHistoryResource;
use Storm\Support\Console\PositiveIntOption;
use Storm\Telemetry\History\GenerationOutOfRange;
use Storm\Telemetry\History\WorkflowHistoryStore;

use function array_map;
use function get_debug_type;
use function is_scalar;
use function is_string;

/**
 * One saga's recorded timeline, the HTTP twin of `storm:telemetry:history`, over the same store the
 * console renders. The window is the store's own cap, so no caller can talk this surface into
 * scanning a retention table.
 *
 * The store's WHOLE answer crosses the wire, not just its rows: `truncated`, `limit`, and the
 * availability fact ride the envelope, so a capped window never reads as a complete timeline and
 * an empty one says which kind of empty it is; the same contract the console twin renders.
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
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SagaHistoryResource
    {
        $this->gate->assertOwnedIdentityForRead('saga.history.read', (string) ($uriVariables['correlationId'] ?? '*'));

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];

        $type = $filters['type'] ?? null;
        $limit = $filters['limit'] ?? null;
        $generation = $filters['generation'] ?? null;
        // @infection-ignore-all equivalent: the cast serves the ANALYSER over a mixed array; the
        // router fills this variable with a string and nothing else, so dropping it changes no value
        $correlationId = (string) ($uriVariables['correlationId'] ?? '');

        // a narrowing parameter that cannot be read is REFUSED, never dropped: dropping it aggregates
        // every run of a reused correlation, and the envelope carries no generation to contradict it,
        // so the caller cannot tell the widened answer from the one they asked for. The declared
        // schema's `minimum` already refuses an out-of-range integer; what reaches here is the value
        // that is no integer at all, which the schema's `type` does not enforce. The parser carries
        // the lower bound as well, so neither half depends on the other staying where it is, and it
        // refuses what an int cannot hold: no `maximum` is declared here, and a cast saturating at
        // `PHP_INT_MAX` would reach the column as a value it cannot store.
        $run = $generation === null ? null : PositiveIntOption::parse($generation);

        if ($generation !== null && $run === null) {
            // @infection-ignore-all equivalent: `sprintf` renders a scalar identically with or
            // without the cast; the cast serves the analyser, the branch beside it carries the type
            throw MalformedQueryParameter::expectingAPositiveInteger('generation', is_scalar($generation) ? (string) $generation : get_debug_type($generation));
        }

        // the window is read through the parser the console twin uses, so one rule answers for both
        // channels: integral in FORM, not merely numeric. is_numeric() blesses '1.5', and a cast
        // would serve a window the caller never asked for while the envelope echoes the narrowed
        // number back as though it had been requested, which is the reading that hides truncation.
        $window = $limit === null ? WorkflowHistoryStore::DEFAULT_LIMIT : PositiveIntOption::parse($limit);

        if ($window === null) {
            // @infection-ignore-all equivalent: same as the generation branch above, the cast is
            // for the analyser and `sprintf` renders the scalar the same either way
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

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * The engine declined the cancel, and the message names WHICH cause a fresh read of the instance
 * establishes, since the engine collapses every refusal to one `false`. A 409 for every cause on
 * purpose: the correlation is not a wrong address, the world is in a state where the cancel is not
 * the right move, and that state may change. Four causes:
 *
 * - No instance carries the correlation, so there is nothing to cancel;
 * - The run has already settled, and a settled saga has nothing left to halt;
 * - The saga still runs and the cancel carried no `force`: an effect-gating wait, where an
 *   in-flight effect is never discarded on a word alone, or a competing step holding it;
 * - The saga still runs and the cancel WAS forced, so only a competing step can have declined:
 *   retry, and never advise force to a caller who already passed it.
 *
 * The read is taken after the refusal, not at the instant the engine judged: the answer describes
 * the instance as the operator's next attempt will find it.
 */
final class SagaCancelRefused extends RuntimeException
{
    public static function noInstance(string $type, string $correlationId): self
    {
        return new self(sprintf(
            'Cancel of %s/%s refused: no saga instance carries this correlation; there is nothing to cancel.',
            $type,
            $correlationId,
        ));
    }

    public static function alreadySettled(string $type, string $correlationId, string $status): self
    {
        return new self(sprintf(
            'Cancel of %s/%s refused: the run has already settled as "%s"; a settled saga has nothing left to halt.',
            $type,
            $correlationId,
            $status,
        ));
    }

    public static function atGatingWaitOrCompetingStep(string $type, string $correlationId): self
    {
        return new self(sprintf(
            'Cancel of %s/%s refused: the saga still runs, parked at an effect-gating wait or held by a competing step this instant. Retry after the in-flight effect settles, or pass "force": true to own the risk.',
            $type,
            $correlationId,
        ));
    }

    public static function atCompetingStepDespiteForce(string $type, string $correlationId): self
    {
        return new self(sprintf(
            'Cancel of %s/%s refused despite force: a competing step holds the saga this instant; retry once that step settles.',
            $type,
            $correlationId,
        ));
    }
}

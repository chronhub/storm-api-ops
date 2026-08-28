<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;
use Storm\Saga\Outbox\RedriveOutcome;

/**
 * The store declined the redrive, and says which guard did it. A 409 on purpose: nothing is wrong
 * with the request, the WORLD is in a state where re-sending that command is not the right move;
 * the state may change, and for one of the refusals `force` is the caller's way of owning the risk.
 *
 * The message carries the same sentence the console prints, so an operator reading a ticket cannot
 * tell which channel produced it. `NotFound` never reaches here: an unknown command is a 404, which
 * the resource declares.
 */
final class SagaRedriveRefused extends RuntimeException
{
    public static function because(RedriveOutcome $outcome, string $correlationId, string $messageId): self
    {
        return new self(sprintf(
            'Redrive of command %s for saga %s refused: %s',
            $messageId,
            $correlationId,
            // @infection-ignore-all; equivalent: the last arm pairs two outcomes the caller never
            // reaches as a refusal, so dropping either label leaves the other answering for a case
            // that never arrives
            match ($outcome) {
                RedriveOutcome::NotDeadLettered => 'that command is not dead-lettered — pending is already in flight, published succeeded, cancelled was recalled by an aborting settle.',
                RedriveOutcome::SagaNotRunning => 'the saga has settled or is gone; re-sending would put an effect in flight that nothing will ever receive.',
                RedriveOutcome::StaleGeneration => 'that command belongs to an earlier run of this correlation and must never cross into the run that replaced it.',
                RedriveOutcome::EffectUnproven => 'nobody proved this effect rolled back, so re-sending may execute it twice. Establish what happened downstream, then pass "force": true with a reason to own the risk.',
                RedriveOutcome::Raced => 'the row moved while this ran — a settle or another operator got there first. Read it again and retry.',
                // both are outcomes the caller never sees as a refusal: applied, or a 404 by declaration
                RedriveOutcome::Redriven, RedriveOutcome::NotFound => 'unexpected outcome.',
            },
        ));
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Storm\Projector\Store\ProjectionLifecycleStore;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The pause action's optional body: a timed pause resumes itself after `forSeconds`, the
 * projector's own timed-pause semantics; omitted or null, the pause is indefinite until `resume`.
 *
 * The bounds are the contract, refused as a 422 before the store ever sees the value. Zero and
 * negatives are lies: the row would say `paused` with a horizon already passed, resumed on the
 * next runner tick, and null already means indefinite, one way to say it. The ceiling is an
 * operational month: an operator pausing longer than 30 days wants `stop`, and an unbounded
 * value would only surface later as a PostgreSQL interval error instead of a clear refusal.
 */
final readonly class PauseProjectionInput
{
    public function __construct(
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(ProjectionLifecycleStore::MAX_PAUSE_SECONDS)]
        public ?int $forSeconds = null,
    ) {}
}

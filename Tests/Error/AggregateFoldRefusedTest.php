<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Error;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Error\AggregateFoldRefused;

/**
 * The one refusal the aggregate read can answer, and the operator meets it as a 422 body with no
 * other context. So the sentence carries the whole next move: which aggregate, the head that
 * tripped the ceiling, the ceiling that stopped it, and the console as the unbounded path. A body
 * that only says "refused" sends an operator hunting a bug in the endpoint instead of reaching for
 * the twin, and one that prints the two numbers in the wrong order reads as a ceiling far past a
 * head that is already over it.
 */
final class AggregateFoldRefusedTest extends TestCase
{
    #[Test]
    public function the_refusal_names_the_aggregate_the_head_that_tripped_it_and_the_way_out(): void
    {
        $message = AggregateFoldRefused::tooLong('account', 'acc-7', 250_000, 100_000)->getMessage();

        $this->assertStringContainsString('"account-acc-7"', $message, 'the addressable pair, the one an operator retries against');
        // the head first, the ceiling second: both ride the same %d, and swapped they still read
        // as a sentence, a plausible one that inverts the fact
        $this->assertStringContainsString('its stream head is at version 250000', $message);
        $this->assertStringContainsString('past the 100000 ceiling', $message);
        // a refusal with no way through is a dead end, and the way it named for months did not exist:
        // no `storm:*` verb folds an aggregate, so it points at the history the fold is made of
        $this->assertStringContainsString('storm:events:inspect --stream', $message);
    }
}

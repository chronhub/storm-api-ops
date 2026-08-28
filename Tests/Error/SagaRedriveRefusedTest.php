<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Error;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Error\SagaRedriveRefused;
use Storm\Saga\Outbox\RedriveOutcome;

/**
 * Every refusal an operator can actually receive says WHY, in its own words. The sentence is the
 * one the console prints, so a ticket quoting it cannot betray which channel produced it; a
 * refusal folding into the generic arm would read as a bug report instead of an instruction.
 */
final class SagaRedriveRefusedTest extends TestCase
{
    #[Test]
    public function every_reachable_refusal_carries_its_own_sentence(): void
    {
        $sentences = [];

        foreach ([
            RedriveOutcome::NotDeadLettered,
            RedriveOutcome::SagaNotRunning,
            RedriveOutcome::StaleGeneration,
            RedriveOutcome::EffectUnproven,
            RedriveOutcome::Raced,
        ] as $outcome) {
            $message = SagaRedriveRefused::because($outcome, 'c-1', 'm-1')->getMessage();

            $this->assertStringContainsString('Redrive of command m-1 for saga c-1 refused:', $message);
            $this->assertStringNotContainsString('unexpected outcome', $message, $outcome->name.' fell into the generic arm');
            $sentences[] = $message;
        }

        $this->assertCount(5, array_unique($sentences), 'two refusals sharing a sentence leave an operator without the reason');
    }
}

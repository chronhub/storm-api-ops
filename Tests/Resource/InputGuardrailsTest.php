<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\CancelSagaInput;
use Storm\ApiOps\Resource\ForgetSubjectInput;
use Storm\ApiOps\Resource\PauseSagaInput;
use Storm\ApiOps\Resource\PauseSagaTypeInput;
use Storm\ApiOps\Resource\RedriveSagaInput;
use Storm\ApiOps\Resource\ResumeSagaInput;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The free-text guardrails, `Actor`'s rule transplanted: every reason, message id and workflow
 * type lands in the audit log, a store column or an event, so each is bounded at 256 BYTES and
 * refuses control and format characters; a newline would forge lines in a plain-text log sink,
 * and an oversized value would be written verbatim into every destination it touches.
 */
final class InputGuardrailsTest extends TestCase
{
    #[Test]
    #[DataProvider('inputs')]
    public function the_byte_ceiling_holds_exactly_at_256(object $atLimit, object $overLimit): void
    {
        // constructed once with every default applied: a promoted default's line only counts as
        // covered when the default fires, and the guard ATTRIBUTES live on those lines, so this
        // touch is what maps this very test into the covering set a mutated bound must answer to
        new ($atLimit::class);

        self::assertCount(0, self::validator()->validate($atLimit), '256 bytes is within the ceiling');
        self::assertGreaterThan(0, count(self::validator()->validate($overLimit)), '257 bytes must be refused');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_forced_redrive_requires_its_reason_and_an_unforced_one_does_not(): void
    {
        // @phpstan-ignore new.resultUnused (the default-construction touch maps this test into the covering set of the attribute lines; see the ceiling test)
        new RedriveSagaInput;

        self::assertGreaterThan(0, count(self::validator()->validate(new RedriveSagaInput(messageId: 'm-1', force: true))), 'force owns a risk, the reason is its written half');
        self::assertCount(0, self::validator()->validate(new RedriveSagaInput(messageId: 'm-1')), 'an unforced redrive needs no reason');
    }

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('controlCarrying')]
    public function a_newline_is_refused_on_every_field_before_it_can_forge_an_audit_line(object $input): void
    {
        // the docblock's "each" holds for both halves only when both ride the full field list: the
        // ceiling walks nine fields, so the control refusal walks the same nine, one field per case
        self::assertGreaterThan(0, count(self::validator()->validate($input)));
    }

    /**
     * @return iterable<string, array{object, object}>
     */
    public static function inputs(): iterable
    {
        $at = str_repeat('a', 256);
        $over = str_repeat('a', 257);

        yield 'cancel reason' => [new CancelSagaInput(workflowType: 'transfer', reason: $at), new CancelSagaInput(workflowType: 'transfer', reason: $over)];

        yield 'cancel workflow type' => [new CancelSagaInput(workflowType: $at), new CancelSagaInput(workflowType: $over)];

        yield 'pause reason' => [new PauseSagaInput(workflowType: 'transfer', reason: $at), new PauseSagaInput(workflowType: 'transfer', reason: $over)];

        yield 'pause workflow type' => [new PauseSagaInput(workflowType: $at), new PauseSagaInput(workflowType: $over)];

        yield 'type-pause reason' => [new PauseSagaTypeInput(reason: $at), new PauseSagaTypeInput(reason: $over)];

        yield 'forget reason' => [new ForgetSubjectInput(reason: $at), new ForgetSubjectInput(reason: $over)];

        yield 'redrive message id' => [new RedriveSagaInput(messageId: $at), new RedriveSagaInput(messageId: $over)];

        yield 'redrive reason' => [new RedriveSagaInput(messageId: 'm-1', force: true, reason: $at), new RedriveSagaInput(messageId: 'm-1', force: true, reason: $over)];

        yield 'resume workflow type' => [new ResumeSagaInput(workflowType: $at), new ResumeSagaInput(workflowType: $over)];
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function controlCarrying(): iterable
    {
        $forged = "ok\nops action=forged";

        yield 'cancel reason' => [new CancelSagaInput(workflowType: 'transfer', reason: $forged)];

        yield 'cancel workflow type' => [new CancelSagaInput(workflowType: $forged)];

        yield 'pause reason' => [new PauseSagaInput(workflowType: 'transfer', reason: $forged)];

        yield 'pause workflow type' => [new PauseSagaInput(workflowType: $forged)];

        yield 'type-pause reason' => [new PauseSagaTypeInput(reason: $forged)];

        yield 'forget reason' => [new ForgetSubjectInput(reason: $forged)];

        yield 'redrive message id' => [new RedriveSagaInput(messageId: $forged)];

        yield 'redrive reason' => [new RedriveSagaInput(messageId: 'm-1', force: true, reason: $forged)];

        yield 'resume workflow type' => [new ResumeSagaInput(workflowType: $forged)];
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}

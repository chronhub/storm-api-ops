<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function trim;

/**
 * The redrive action's body, the console contract verbatim: the message id names WHICH dead command
 * to send back into flight, the one `GET /_storm/sagas/{correlationId}` prints beside its evidence,
 * and `force` owns the risk when that evidence proves nothing.
 *
 * The reason is required WITH force and only with it, the same rule the console applies: re-sending
 * an effect nobody proved rolled back may execute it twice, and a decision of that weight has to be
 * attributable in the ops log. Without force the guards do the deciding, so no justification is owed.
 *
 * The cross-field rule rides `Assert\Callback` rather than `Assert\Expression`: the latter needs
 * `symfony/expression-language`, and a package does not grow a dependency to phrase one condition it
 * can state in PHP.
 */
final readonly class RedriveSagaInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public string $messageId = '',
        public bool $force = false,
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public ?string $reason = null,
    ) {}

    /**
     * A forced redrive with no stated why is an unattributable decision: whoever reads the ops log
     * afterward must find the judgment, not just the act.
     */
    #[Assert\Callback]
    public function validateForcedReasonIsStated(ExecutionContextInterface $context): void
    {
        if ($this->force && trim($this->reason ?? '') === '') {
            $context->buildViolation('A forced redrive requires a reason: re-sending an unproven effect is a decision, and it has to be attributable.')
                ->atPath('reason')
                ->addViolation();
        }
    }
}

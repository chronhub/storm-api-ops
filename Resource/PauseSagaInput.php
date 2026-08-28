<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The instance freeze's body, the console contract verbatim: the workflow type names WHICH saga
 * under the correlation, and the reason carries the freeze's attributability, an operator decision
 * an inspection must be able to explain.
 */
final readonly class PauseSagaInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public string $workflowType = '',
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public ?string $reason = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The cancel action's body, the console contract verbatim: the workflow type names WHICH saga
 * under the correlation, the reason lands on `SagaCancelled` as the durable audit trail, and
 * `force` owns the risk at an effect-gating wait, where an unforced cancel is refused.
 */
final readonly class CancelSagaInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public string $workflowType = '',
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public ?string $reason = null,
        public bool $force = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The instance thaw's body: only the workflow type, naming WHICH saga under the correlation. The
 * resume needs no reason; lifting a freeze restores the normal regime rather than deciding a new
 * one.
 */
final readonly class ResumeSagaInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public string $workflowType = '',
    ) {}
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The type freeze's body: just the reason, the workflow type riding the URI. Optional like the
 * console's `--reason`, but a fleet-wide freeze is an incident move; say why.
 */
final readonly class PauseSagaTypeInput
{
    public function __construct(
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public ?string $reason = null,
    ) {}
}

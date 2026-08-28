<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The forget action's body: the subject rides the URI, so the body carries only the optional
 * stated why. A GDPR erasure usually has one, the request's ticket or the legal basis, and the ops
 * log is where an auditor expects to find it beside WHO acted. Optional on purpose: unlike a forced
 * redrive, the forget is not overriding any evidence, so no justification is owed.
 */
final readonly class ForgetSubjectInput
{
    public function __construct(
        #[Assert\Length(max: 256, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'Control and format characters are refused: this value is copied into audit lines, store columns and events.')]
        public ?string $reason = null,
    ) {}
}

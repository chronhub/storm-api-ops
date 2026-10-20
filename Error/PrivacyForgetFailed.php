<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * A forget that failed or stopped partway, surfaced as a 500 whose message is the audit outcome.
 *
 * Log formatters write each message in an exception chain, and a driver message may quote a value of the
 * subject being erased, so this exception carries only the audit outcome. The outline in that outcome
 * keeps the class, code and SQLSTATE of each level, and the messages Storm writes, so the 500 still names
 * what failed.
 */
final class PrivacyForgetFailed extends RuntimeException
{
    /**
     * Takes the outcome the audit line records, `failed: ` or `incomplete: ` followed by the outline.
     */
    public static function because(string $outcome): self
    {
        return new self('privacy_forget '.$outcome);
    }
}

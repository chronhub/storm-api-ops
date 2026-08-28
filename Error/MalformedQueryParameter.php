<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

use function sprintf;

/**
 * A query parameter present and unusable: the ops surface's 422, declared on the resource's
 * `exceptionToStatus`, and the twin of the console options that refuse the same value in words.
 *
 * A narrowing parameter is the one place silence costs most. Dropped, the request WIDENS: a caller
 * who asked for one run of a reused correlation is served every run it ever had, in an envelope
 * that never echoes what was applied, so the answer is both wrong and unfalsifiable at the caller.
 */
final class MalformedQueryParameter extends RuntimeException
{
    public static function expectingAPositiveInteger(string $parameter, string $given): self
    {
        return new self(sprintf(
            'The "%s" query parameter must be a positive integer; got "%s". Omitting it is what widens the request; a value that cannot be read never does.',
            $parameter,
            $given,
        ));
    }

    public static function expectingANonEmptySet(string $parameter): self
    {
        return new self(sprintf(
            'The "%s" query parameter must name at least one value; got none. A trace with no set would match every stored event rather than nothing, which is the widening this refuses.',
            $parameter,
        ));
    }
}

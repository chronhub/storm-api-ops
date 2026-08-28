<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

use function implode;
use function sprintf;

/**
 * A describe request naming a section the document does not carry: the ops surface's 422,
 * declared on the resource's `exceptionToStatus`, mirroring the console twin's `--section`
 * refusal word for word. The refusal is loud and LISTS the valid names, because a caller probing
 * a section that does not exist is one typo away from the one that does. The valid list rides in
 * as an argument so this class stays free of the bundle edge the provider owns.
 */
final class UnknownDescribeSection extends RuntimeException
{
    /**
     * @param  list<string>  $valid  the section names the document does carry, in document order
     */
    public static function named(string $section, array $valid): self
    {
        return new self(sprintf('Unknown section "%s". Valid sections: %s.', $section, implode(', ', $valid)));
    }
}

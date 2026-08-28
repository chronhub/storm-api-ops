<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * A destructive action reached the ops surface with no owned identity: refused before touching
 * anything, the package's own fail-closed backstop under the app's firewall. A 403 on purpose.
 * The caller is not missing a parameter, it is missing WHO IT IS: the audit trail names who
 * acted, and an anonymous mutation would make that line a blank on a destructive action.
 *
 * Dev and demo environments opt out explicitly with `storm_api_ops.allow_anonymous_mutations`;
 * the default refuses.
 */
final class AnonymousMutationRefused extends RuntimeException
{
    public static function for(string $action, string $subject): self
    {
        return new self(sprintf(
            'Mutation "%s" on "%s" refused: no authenticated actor. Wire a Bureau IdentityProvider and bind the actor at the boundary, or opt out explicitly with storm_api_ops.allow_anonymous_mutations (dev only).',
            $action,
            $subject,
        ));
    }
}

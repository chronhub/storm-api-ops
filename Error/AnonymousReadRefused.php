<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * A read reached the ops surface with no owned identity: refused before touching any store, the
 * read half of the package's fail-closed backstop under the app's firewall. A 403 on purpose.
 * The reads serve hydrated rows, event payloads above all, so the one misconfiguration the docs
 * warn about, an `access_control` pattern that forgets the `/api` mount, must fail as loud here
 * as it does on the destructive verbs, never drain the store silently.
 *
 * Dev and demo environments opt out explicitly with `storm_api_ops.allow_anonymous_reads`; the
 * default refuses. The `describe` endpoint is exempt by design, serving compiled wiring and
 * never a row.
 */
final class AnonymousReadRefused extends RuntimeException
{
    public static function for(string $action, string $subject): self
    {
        return new self(sprintf(
            'Read "%s" on "%s" refused: no authenticated actor. Wire a Bureau IdentityProvider and bind the actor at the boundary, or opt out explicitly with storm_api_ops.allow_anonymous_reads (dev only).',
            $action,
            $subject,
        ));
    }
}

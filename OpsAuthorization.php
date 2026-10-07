<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Storm\Bureau\Actor;
use Throwable;

/**
 * Application-owned permission policy for an authenticated actor on the ops surface.
 *
 * Decisions are evaluated per call; implementations must not retain a previous request's grant.
 * The action and subject identify the requested operation, not credentials. Backend failures
 * propagate instead of granting access. Role names and tenant boundaries belong to the app.
 */
interface OpsAuthorization
{
    /**
     * Allow this actor to inspect the requested subject.
     *
     * @throws Throwable when the application's permission backend cannot answer
     */
    public function canRead(Actor $actor, string $action, string $subject): bool;

    /**
     * Allow this actor to perform the requested mutation.
     *
     * @throws Throwable when the application's permission backend cannot answer
     */
    public function canMutate(Actor $actor, string $action, string $subject): bool;
}

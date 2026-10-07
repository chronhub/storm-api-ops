<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * An identified caller lacks the application's explicit ops permission.
 */
final class OperatorPermissionRefused extends AccessDeniedHttpException
{
    public static function for(string $action, string $subject): self
    {
        return new self(sprintf('Operation "%s" on "%s" refused: operator permission is required.', $action, $subject));
    }
}

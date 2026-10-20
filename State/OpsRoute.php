<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

/**
 * Reads a route value of an ops operation as the string the router supplies.
 *
 * API Platform hands the URI variables and the operation's extra properties over as arrays of `mixed`,
 * which the router and the resource declarations only ever fill with strings. Every provider and
 * processor reads them here, so the cast that serves the analyzer is written once.
 */
final readonly class OpsRoute
{
    /**
     * @param  array<array-key, mixed>|null  $values  the URI variables, or the operation's extra
     *                                                properties, null when it declares none
     */
    public static function string(?array $values, string $name, string $default = ''): string
    {
        $value = $values[$name] ?? $default;

        // equivalent: the cast serves the analyzer, the array being typed mixed; the router and the
        // resource declarations only ever supply strings, so dropping it changes no runtime value
        // @infection-ignore-all
        return (string) $value;
    }
}

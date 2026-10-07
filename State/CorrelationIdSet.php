<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function is_string;
use function trim;

/**
 * The correlation window's WIDTH arithmetic: the one parse that reads a comma-separated `ids`
 * parameter, and the one ceiling on how many of them may reach `CorrelationFeedFilter`.
 *
 * A width is not a page, and confusing the two is the mistake this class is placed beside
 * `PageWindow` to prevent. `PageWindow` bounds the ROWS a read returns; this bounds the terms of the
 * predicate that selects them. They are independent: one id can fill a page, and a set at the
 * ceiling can match nothing.
 *
 * Shared rather than copied, because both HTTP surfaces over this read parse the same parameter and
 * must admit the same set. What they do at the ceiling differs and stays theirs: the JSON window
 * refuses, having no channel to report a narrowing, while the screen narrows and says so.
 *
 * The ceiling is the widest ONE-HOP lineage the write side can mint: a parent, plus the 64 children
 * `Storm\Saga\Child\ChildSpawner` lets one saga spawn. One hop is the unit both surfaces trace, a
 * deeper family being asked for again with the children as the typed set, so the ceiling is not the
 * whole tree and was never meant to be. Below it a legitimate lineage would be refused; above it
 * nothing one hop can produce is gained, while every extra id is carried three times over, as a
 * bound term of the `IN` list, as part of the audit subject, and in the query string the screen
 * echoes back.
 */
final readonly class CorrelationIdSet
{
    public const int MAX_IDS = 65;

    /**
     * Every usable id a comma-separated parameter names, trimmed, blanks dropped, order kept.
     *
     * Reads `mixed` because a filter bag and a query bag are both typed that way, and anything that
     * is not a string names no id, the same answer a blank parameter gets.
     *
     * @return list<string>
     */
    public static function parse(mixed $raw): array
    {
        $parts = array_map(trim(...), explode(',', is_string($raw) ? $raw : ''));

        // filtered on emptiness, never on truthiness: a bare `array_filter` also drops the id "0",
        // and a set silently one id short is served as the complete trace
        return array_filter($parts, static fn (string $part): bool => $part !== '')
            |> array_values(...);
    }

    /**
     * @param  list<string>  $ids
     */
    public static function isTooWide(array $ids): bool
    {
        return count($ids) > self::MAX_IDS;
    }

    /**
     * The head of the set, cut to the ceiling, for the surface that reports a narrowing instead of
     * refusing it.
     *
     * Cutting NARROWS the predicate, so the result can only match fewer rows than asked, never more.
     * That direction is what makes it a safe answer at all; the opposite one, a dropped narrowing
     * parameter, is the widening every surface here refuses.
     *
     * @param  non-empty-list<string>  $ids
     * @return non-empty-list<string>
     */
    public static function narrow(array $ids): array
    {
        return self::isTooWide($ids) ? array_slice($ids, 0, self::MAX_IDS) : $ids;
    }
}

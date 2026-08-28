<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

/**
 * The ops collections' window arithmetic: server-owned caps over the declared `after` / `limit`
 * query parameters. The parameter schemas document the bounds; this enforces them, so a caller can
 * never talk the surface into an unbounded read. The `StreamReader` doc is explicit: a read is as
 * big as its query, and pdo_pgsql buffers it whole.
 */
final readonly class PageWindow
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    /**
     * @param  array<string, mixed>  $filters
     * @return positive-int
     */
    public static function limit(array $filters): int
    {
        $raw = $filters['limit'] ?? null;
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return non-negative-int
     */
    public static function afterPosition(array $filters): int
    {
        $raw = $filters['after'] ?? null;

        return is_numeric($raw) ? max(0, (int) $raw) : 0;
    }

    /**
     * The lane a browse was narrowed to, null when it browses every stream.
     *
     * Read HERE and not per caller: a screen renders the directory its provider served, so a second
     * spelling of "which lane is this" would let the page name one narrowing while the read applied
     * another.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function category(array $filters): ?string
    {
        $raw = $filters['category'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function afterStream(array $filters): string
    {
        $raw = $filters['after'] ?? null;

        return is_string($raw) ? $raw : '';
    }
}

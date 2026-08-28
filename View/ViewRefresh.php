<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Symfony\Component\HttpFoundation\Request;

use function is_numeric;
use function max;
use function min;

/**
 * The reload interval an operator asked for, clamped where a screen can afford it.
 *
 * Clamped and never refused: a refresh box is a comfort control on a read-only page, and a typo in
 * it must not cost the operator the screen they came for. The ceiling is what keeps a forgotten tab
 * from polling the store forever.
 */
final readonly class ViewRefresh
{
    /** The longest interval worth honouring; beyond it a page is not refreshing, it is idling. */
    public const int MAX_SECONDS = 300;

    /**
     * The query key this reads. Named here rather than spelled per screen: a screen that refuses
     * the query keys it does not know must be able to tell the chrome's own from an operator's typo.
     */
    public const string KEY = 'refresh';

    public static function secondsFrom(Request $request): int
    {
        $raw = $request->query->get(self::KEY);

        // @infection-ignore-all equivalent: the floor's value below 1 is unobservable, every screen
        // polling on `> 0`, so zero and a negative are the same answer to a reader
        return is_numeric($raw) ? max(0, min(self::MAX_SECONDS, (int) $raw)) : 0;
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use Throwable;

/**
 * The child correlations of one correlation, which is all a screen needs to widen a trace.
 *
 * It exists so the screen stops knowing where children come from or what shape they arrive in. A
 * lineage is resolved where it is known and the framework offers no preset for it; this names the
 * one question a view is allowed to ask, and nothing beyond it.
 */
interface CorrelationLineage
{
    /**
     * Every child correlation of this one, empty when it has none.
     *
     * The walk is one hop: a caller wanting a whole tree asks again with what it got, so the
     * widening stays visible to whoever asked for it.
     *
     * @return list<string>
     *
     * @throws Throwable whatever the underlying store raises; the layer declares the seam and
     *                   cannot know what an implementation reaches for
     */
    public function childrenOf(string $correlationId): array;
}

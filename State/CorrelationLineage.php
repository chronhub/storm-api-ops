<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use Throwable;

/**
 * The child correlations of one correlation, which is all a reader needs to widen a trace.
 *
 * It exists so the reader stops knowing where children come from or what shape they arrive in. A
 * lineage is resolved where it is known and the framework offers no preset for it; this names the
 * one question a consumer is allowed to ask, and nothing beyond it. It renders nothing: a page
 * built on it belongs to its consumer.
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

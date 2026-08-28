<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A dispatcher that only remembers: the tests assert WHICH announcements a processor made, and
 * that a refused verb announced nothing.
 */
final class CollectingEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

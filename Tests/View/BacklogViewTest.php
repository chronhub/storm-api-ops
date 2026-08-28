<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\BacklogResource;
use Storm\ApiOps\View\BacklogView;

final class BacklogViewTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_failed_collector_is_announced_above_the_numbers(): void
    {
        // the ordering IS the assertion: a block missing because its collector threw looks exactly
        // like a queue that is empty, and an operator reading top-down would act on the wrong one
        $html = new BacklogView()->render(
            new BacklogResource([$this->family('storm_inbox_rows', 'rows', [[], 7])], ['OutboxMetricsCollector']),
            0,
        );

        $degradedAt = strpos($html, 'OutboxMetricsCollector');
        $numbersAt = strpos($html, 'storm_inbox_rows');

        self::assertIsInt($degradedAt);
        self::assertIsInt($numbersAt);
        self::assertLessThan($numbersAt, $degradedAt, 'a failed collector is not a footnote');
    }

    #[Test]
    public function a_healthy_read_carries_no_degraded_block_at_all(): void
    {
        $html = new BacklogView()->render(new BacklogResource([$this->family('storm_inbox_rows', 'rows', [[], 0])], []), 0);

        self::assertStringNotContainsString('class="degraded"', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function retained_rows_are_never_presented_as_waiting(): void
    {
        // the saga command outbox holds millions of `published` rows BY DESIGN; a page framing them
        // as work in progress would invent an incident every time it is opened
        $html = new BacklogView()->render(
            new BacklogResource([$this->family('storm_saga_outbox', 'Saga command outbox rows by status', [['status' => 'published'], 6017437])], []),
            0,
        );

        self::assertStringContainsString('status=published', $html);
        self::assertStringContainsString('6017437', $html);
        self::assertStringNotContainsString('waiting', $html);
        self::assertStringNotContainsString('pending rows', $html);
    }

    #[Test]
    public function an_age_family_is_rendered_in_seconds_and_a_count_is_not(): void
    {
        // an age printed as a bare number reads as a count, and 41 seconds of lag would be indexed
        // against the wrong scale by whoever reads the column
        $html = new BacklogView()->render(new BacklogResource([
            $this->family('storm_outbox_events_oldest_pending_age_seconds', 'age', [[], 41.5]),
            $this->family('storm_inbox_rows', 'rows', [[], 12]),
        ], []), 0);

        self::assertStringContainsString('41.500 s', $html);
        self::assertStringContainsString('<td class="n">12</td>', $html);
    }

    #[Test]
    public function a_family_that_answered_with_nothing_says_so_rather_than_rendering_a_bare_table(): void
    {
        $html = new BacklogView()->render(new BacklogResource([$this->family('storm_inbox_rows', 'rows', null)], []), 0);

        self::assertStringContainsString('nothing to report', $html);
    }

    #[Test]
    public function no_family_at_all_says_why_the_page_is_empty(): void
    {
        $html = new BacklogView()->render(new BacklogResource([], []), 0);

        self::assertStringContainsString('No backlog family answered', $html);
    }

    #[Test]
    public function the_page_states_the_bound_of_what_it_can_see(): void
    {
        // a screen that let an operator believe it covered the broker would be worse than no screen
        $html = new BacklogView()->render(new BacklogResource([], []), 0);

        self::assertStringContainsString('What a broker holds is invisible here', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_label_carrying_markup_cannot_reach_the_page(): void
    {
        $html = new BacklogView()->render(
            new BacklogResource([$this->family('storm_inbox_rows', '<b>help</b>', [['q' => '<script>x</script>'], 1])], []),
            0,
        );

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringNotContainsString('<b>help</b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * @param  array{0: array<string, string>, 1: int|float}|null  $sample
     * @return array{name: string, help: string, samples: list<array{labels: array<string, string>, value: int|float}>}
     */
    private function family(string $name, string $help, ?array $sample): array
    {
        return [
            'name' => $name,
            'help' => $help,
            'samples' => $sample === null ? [] : [['labels' => $sample[0], 'value' => $sample[1]]],
        ];
    }
}

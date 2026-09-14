<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\State\CorrelationIdSet;
use Storm\ApiOps\State\PageWindow;
use Storm\Saga\Child\ChildSpawner;

use function array_map;
use function count;
use function implode;
use function range;

final class CorrelationIdSetTest extends TestCase
{
    #[Test]
    public function the_parse_trims_and_drops_what_names_no_id(): void
    {
        // a trailing or doubled comma is a typing accident; an empty id would match rows whose
        // header is absent rather than narrowing to the ones asked for
        $this->assertSame(['corr-9', 'corr-4'], CorrelationIdSet::parse(' corr-9 ,, corr-4 ,'));
        $this->assertSame(['corr-9'], CorrelationIdSet::parse('corr-9'));
        $this->assertSame([], CorrelationIdSet::parse(' , , '));
        $this->assertSame([], CorrelationIdSet::parse(''));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_id_that_reads_as_falsy_still_names_an_id(): void
    {
        // dropping BLANKS is the intent, and filtering on truthiness is not that: "0" is a value the
        // header can carry, and a set quietly one id short would be served as the complete trace
        $this->assertSame(['0'], CorrelationIdSet::parse('0'));
        $this->assertSame(['corr-9', '0'], CorrelationIdSet::parse('corr-9, 0 '));
    }

    #[Test]
    #[Group('adversarial')]
    public function anything_that_is_not_a_string_names_no_id(): void
    {
        // both bags handing this parameter over are typed mixed, so an array from `?ids[]=x` or a
        // null from an absent parameter arrive here; naming nothing is the one answer that cannot
        // widen, since every caller then refuses or prompts rather than tracing the whole store
        $this->assertSame([], CorrelationIdSet::parse(null));
        $this->assertSame([], CorrelationIdSet::parse(['corr-9']));
        $this->assertSame([], CorrelationIdSet::parse(42));
    }

    #[Test]
    public function the_order_typed_is_the_order_queried(): void
    {
        // the store returns a trace in `sequence_no` order, so the set is not sorted on the way in;
        // an operator reading the echoed form must recognise what they typed
        $this->assertSame(['b', 'a', 'c'], CorrelationIdSet::parse('b,a,c'));
    }

    #[Test]
    public function the_ceiling_admits_a_whole_one_hop_lineage(): void
    {
        // the value is DERIVED and this pins the derivation: a parent plus every child one saga may
        // spawn, so a lineage the write side can legitimately mint is never refused by the surface
        // that traces it. Pinned rather than computed on purpose: a coordination-side ceiling that
        // moved would otherwise widen an HTTP predicate and an audit subject with no decision taken
        $this->assertSame(ChildSpawner::MAX_CHILDREN + 1, CorrelationIdSet::MAX_IDS);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_width_ceiling_holds_on_the_boundary_and_one_step_past_it(): void
    {
        // an off-by-one here is a ceiling a caller can still talk past, and it is the only thing
        // standing between a named trace and an `IN` list as wide as the caller cares to type
        $this->assertFalse(CorrelationIdSet::isTooWide($this->ids(CorrelationIdSet::MAX_IDS - 1)));
        $this->assertFalse(CorrelationIdSet::isTooWide($this->ids(CorrelationIdSet::MAX_IDS)));
        $this->assertTrue(CorrelationIdSet::isTooWide($this->ids(CorrelationIdSet::MAX_IDS + 1)));
    }

    #[Test]
    public function a_set_at_the_ceiling_is_narrowed_to_itself(): void
    {
        $atTheCeiling = $this->ids(CorrelationIdSet::MAX_IDS);

        $this->assertSame($atTheCeiling, CorrelationIdSet::narrow($atTheCeiling));
    }

    #[Test]
    #[Group('adversarial')]
    public function narrowing_keeps_the_head_of_the_set_and_cuts_the_tail(): void
    {
        // the head, because the ids are queried in the order typed: a caller pasting a batch reads
        // the first rows of the answer it expected, not an arbitrary slice of it
        $narrowed = CorrelationIdSet::narrow($this->ids(CorrelationIdSet::MAX_IDS + 20));

        $this->assertCount(CorrelationIdSet::MAX_IDS, $narrowed);
        $this->assertSame('corr-1', $narrowed[0]);
        $this->assertSame('corr-'.CorrelationIdSet::MAX_IDS, $narrowed[count($narrowed) - 1]);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_predicate_width_is_not_the_row_window(): void
    {
        // the one confusion this class is placed beside `PageWindow` to prevent: the terms of the
        // predicate and the rows it selects are independent bounds, and a shared value would let a
        // reader move one believing they moved the other
        $this->assertNotSame(PageWindow::MAX_LIMIT, CorrelationIdSet::MAX_IDS);
        $this->assertNotSame(PageWindow::DEFAULT_LIMIT, CorrelationIdSet::MAX_IDS);
    }

    #[Test]
    public function a_set_at_the_ceiling_survives_a_round_trip_through_the_parse(): void
    {
        // the form echoes the queried set back as a comma-separated value and the operator resubmits
        // it, so a set the surface just admitted must parse back to itself, id for id
        $atTheCeiling = $this->ids(CorrelationIdSet::MAX_IDS);

        $this->assertSame($atTheCeiling, CorrelationIdSet::parse(implode(',', $atTheCeiling)));
    }

    /**
     * @return non-empty-list<string>
     */
    private function ids(int $count): array
    {
        return array_map(static fn (int $i): string => 'corr-'.$i, range(1, $count));
    }
}

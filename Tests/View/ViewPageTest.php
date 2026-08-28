<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Tests\Fixture\StubUrlGenerator;
use Storm\ApiOps\View\ViewPage;

final class ViewPageTest extends TestCase
{
    #[Test]
    public function the_links_carry_the_prefix_the_router_gives_them(): void
    {
        // the whole reason the navigation generates instead of assembling: a screen sits at
        // `/_storm/view/...` in its metadata and under a mount in an application, and only the
        // router knows which. Cutting the prefix out of the current path lies behind a proxy.
        $html = $this->page(['storm_view_backlog', 'storm_view_correlations'])->render('backlog', '');

        self::assertStringContainsString('href="/api/_storm/view/storm_view_correlations"', $html);
    }

    #[Test]
    public function the_screen_being_read_is_named_and_not_linked(): void
    {
        // a link to here reads as somewhere else
        $html = $this->page(['storm_view_backlog', 'storm_view_correlations'])->render('backlog', '');

        self::assertStringContainsString('<strong>backlog</strong>', $html);
        self::assertStringNotContainsString('href="/api/_storm/view/storm_view_backlog"', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_screen_the_application_did_not_import_costs_a_link_not_the_page(): void
    {
        // the surface is opt-in per resource: a consumer taking a subset must still get its pages,
        // and a generator that throws on an absent route would take the whole document with it
        $html = $this->page(['storm_view_backlog'])->render('backlog', '<p>body</p>');

        self::assertStringContainsString('<p>body</p>', $html);
        self::assertStringContainsString('<strong>correlation trace</strong>', $html);
    }

    #[Test]
    public function a_page_with_no_router_still_renders_without_a_navigation(): void
    {
        // the default construction the templates use in isolation; the body is what a test judges
        $html = new ViewPage()->render('backlog', '<p>body</p>');

        self::assertStringContainsString('<p>body</p>', $html);
        self::assertStringNotContainsString('<nav>', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_title_is_escaped_like_any_other_stored_string(): void
    {
        $html = new ViewPage()->render('<script>x</script>', '');

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function the_page_polls_only_when_an_interval_was_asked_for(): void
    {
        self::assertStringNotContainsString('setTimeout', new ViewPage()->render('backlog', '', 0));
        self::assertStringContainsString('9000', new ViewPage()->render('backlog', '', 9));
        self::assertStringContainsString('1000', new ViewPage()->render('backlog', '', 1));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_page_rendered_without_an_interval_stays_still(): void
    {
        // the DEFAULT is the assertion: a screen built by a caller that never mentions refresh must
        // not reload on its own, or every page in the surface would poll the store forever
        self::assertStringNotContainsString('setTimeout', new ViewPage()->render('backlog', ''));
    }

    /**
     * @param  list<string>  $known
     */
    private function page(array $known): ViewPage
    {
        return new ViewPage(new StubUrlGenerator($known));
    }
}

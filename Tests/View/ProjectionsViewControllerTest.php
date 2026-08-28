<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\ProjectionResourceFactory;
use Storm\ApiOps\State\ProjectionsProvider;
use Storm\ApiOps\View\ProjectionsView;
use Storm\ApiOps\View\ProjectionsViewController;
use Storm\Chronicler\Store\StreamReader;
use Storm\EventLinks\DerivedStreamHead;
use Storm\EventLinks\DerivedStreamRevision;
use Storm\Projector\Freshness\ProjectionWaiter;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionRow;
use Storm\Projector\Store\ProjectionStatus;
use Symfony\Component\HttpFoundation\Request;

final class ProjectionsViewControllerTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_by_the_provider_the_screen_reuses(): void
    {
        $catalog = $this->createMock(ProjectionCatalog::class);
        $catalog->expects($this->never())->method('all');

        $this->expectException(AnonymousReadRefused::class);

        $this->controller($catalog, anonymous: false)(Request::create('/_storm/view/projections'));
    }

    #[Test]
    public function an_empty_registry_renders_the_page_that_says_why(): void
    {
        $catalog = $this->createStub(ProjectionCatalog::class);
        $catalog->method('all')->willReturn([]);

        $response = $this->controller($catalog)(Request::create('/_storm/view/projections'));
        $body = $response->getContent();

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertIsString($body);
        self::assertStringContainsString('nothing was declared', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_declared_projection_reaches_the_page_as_a_resource(): void
    {
        // the empty-registry cases never enter the mapping, and that mapping is a layer boundary:
        // unwrapped, the store's own row would flow to a template built for the resource. It is the
        // same shape that survived every value assertion on the dead-letter window, so the read is
        // exercised with something in it rather than assumed.
        $catalog = $this->createStub(ProjectionCatalog::class);
        $catalog->method('all')->willReturn([$this->row('rm_account_balance')]);
        $catalog->method('hasLiveLease')->willReturn(true);

        $body = $this->controller($catalog)(Request::create('/_storm/view/projections'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('rm_account_balance', $body);
        // `lag` lives on the resource and nowhere on the stored row: a column carrying it is the
        // proof the mapping ran
        self::assertStringContainsString('<th class="n">lag</th>', $body);
        self::assertStringContainsString('worker-a (live', $body);
    }

    private function row(string $name): ProjectionRow
    {
        return new ProjectionRow(
            name: $name,
            status: ProjectionStatus::Running,
            lastPosition: 42,
            mode: 'persistent',
            categories: [],
            eventClasses: [],
            sourceStream: null,
            sourceRevision: 0,
            targetStream: null,
            targetPrefix: null,
            leaseOwner: 'worker-a',
            leaseUntil: '2026-08-23T10:05:00Z',
            lastHeartbeatAt: null,
            pauseUntil: null,
            generation: 1,
        );
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refresh_box_is_clamped_at_both_ends(): void
    {
        self::assertStringNotContainsString('setTimeout', $this->body('?refresh=-5'));
        self::assertStringContainsString('300000', $this->body('?refresh=99999'));
        self::assertStringContainsString('4000', $this->body('?refresh=4'));
        self::assertStringNotContainsString('setTimeout', $this->body('?refresh=abc'));
    }

    private function body(string $query): string
    {
        $catalog = $this->createStub(ProjectionCatalog::class);
        $catalog->method('all')->willReturn([]);

        $content = $this->controller($catalog)(Request::create('/_storm/view/projections'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    private function waiter(ProjectionCatalog $catalog): ProjectionWaiter
    {
        // the concrete waiter, built the way the module's own suites build it: the factory takes the
        // class and not the freshness contract it implements, so a double is not available here
        return new ProjectionWaiter(
            $catalog,
            $this->createStub(StreamReader::class),
            new ProjectionRegistry,
            $this->createStub(DerivedStreamHead::class),
            $this->createStub(DerivedStreamRevision::class),
        );
    }

    private function controller(ProjectionCatalog $catalog, bool $anonymous = true): ProjectionsViewController
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new ProjectionsViewController(
            new ProjectionsProvider(
                $catalog,
                new ProjectionResourceFactory($catalog, $this->waiter($catalog)),
                new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
            ),
            new ProjectionsView,
        );
    }
}

<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\BacklogProvider;
use Storm\ApiOps\Tests\Fixture\ThrowingCollector;
use Storm\ApiOps\View\BacklogView;
use Storm\ApiOps\View\BacklogViewController;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;
use Symfony\Component\HttpFoundation\Request;

final class BacklogViewControllerTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused_by_the_provider_the_screen_reuses(): void
    {
        // the screen states no posture of its own: it calls the provider, which carries the gate.
        // A second gate here would be a second thing to keep true.
        $this->expectException(AnonymousReadRefused::class);

        $this->controller([], anonymous: false)(Request::create('/_storm/view/backlog'));
    }

    #[Test]
    public function the_screen_answers_html_built_from_the_providers_own_read(): void
    {
        $response = $this->controller([$this->collector('storm_inbox_rows', 7)])(Request::create('/_storm/view/backlog'));
        $body = $response->getContent();

        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertIsString($body);
        self::assertStringContainsString('storm_inbox_rows', $body);
        self::assertStringContainsString('7', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_collector_that_threw_reaches_the_page_as_a_named_failure(): void
    {
        // the JSON read names it; the screen must not quietly drop it on the way to the markup
        $body = $this->controller([new ThrowingCollector])(Request::create('/_storm/view/backlog'))->getContent();

        self::assertIsString($body);
        self::assertStringContainsString('ThrowingCollector', $body);
        self::assertStringContainsString('MISSING rather than empty', $body);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_refresh_box_is_clamped_at_both_ends(): void
    {
        self::assertStringNotContainsString('setTimeout', $this->body('?refresh=-5'));
        self::assertStringContainsString('300000', $this->body('?refresh=99999'));
        self::assertStringContainsString('6000', $this->body('?refresh=6'));
        self::assertStringNotContainsString('setTimeout', $this->body('?refresh=abc'));
    }

    private function body(string $query): string
    {
        $content = $this->controller([$this->collector('storm_inbox_rows', 1)])(Request::create('/_storm/view/backlog'.$query))->getContent();

        self::assertIsString($content);

        return $content;
    }

    private function collector(string $family, int $value): MetricsCollector
    {
        return new class($family, $value) implements MetricsCollector
        {
            public function __construct(private readonly string $family, private readonly int $value) {}

            public function families(): array
            {
                return [MetricFamily::gauge($this->family, 'rows', [new MetricSample([], $this->value)])];
            }
        };
    }

    /**
     * @param  list<MetricsCollector>  $collectors
     */
    private function controller(array $collectors, bool $anonymous = true): BacklogViewController
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new BacklogViewController(
            new BacklogProvider(
                new MetricsExposition($collectors, new PrometheusTextRenderer, $this->createStub(Connection::class), 0),
                new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
            ),
            new BacklogView,
        );
    }
}

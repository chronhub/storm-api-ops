<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Get;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\State\BacklogProvider;
use Storm\ApiOps\Tests\Fixture\OtherThrowingCollector;
use Storm\ApiOps\Tests\Fixture\ThrowingCollector;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;

final class BacklogProviderTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_read_is_refused(): void
    {
        $this->expectException(AnonymousReadRefused::class);

        $this->provider([], anonymous: false)->provide(new Get);
    }

    #[Test]
    public function only_the_backlog_families_are_served(): void
    {
        // the endpoint answers "what is waiting"; serving every family would make it a second
        // metrics endpoint wearing another content type, which is the decision it exists not to reopen
        $page = $this->provider([$this->collector([
            MetricFamily::gauge('storm_outbox_events', 'depth', [new MetricSample(['status' => 'pending'], 12)]),
            MetricFamily::gauge('storm_saga_instances', 'not a backlog number', [new MetricSample([], 3)]),
        ])])->provide(new Get);

        self::assertSame(['storm_outbox_events'], array_column($page->families, 'name'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_backlog_family_sitting_after_a_foreign_one_is_still_served(): void
    {
        // the ordering case a happy-path fixture hides: a selection that STOPS at the first
        // non-backlog family reads identically when the wanted rows happen to come first
        $page = $this->provider([$this->collector([
            MetricFamily::gauge('storm_saga_instances', 'not a backlog number', [new MetricSample([], 3)]),
            MetricFamily::gauge('storm_inbox_rows', 'rows', [new MetricSample([], 7)]),
        ])])->provide(new Get);

        self::assertSame(['storm_inbox_rows'], array_column($page->families, 'name'));
    }

    #[Test]
    #[Group('adversarial')]
    public function two_failing_collectors_are_both_named(): void
    {
        $page = $this->provider([new ThrowingCollector, new OtherThrowingCollector])->provide(new Get);

        self::assertSame(['ThrowingCollector', 'OtherThrowingCollector'], $page->degraded);
    }

    #[Test]
    public function a_collector_failing_more_than_once_is_named_once(): void
    {
        // an operator acts on WHICH block is missing; two failures of one collector are still one
        // absent block, and a repeated name would read as two
        $page = $this->provider([new ThrowingCollector, new ThrowingCollector])->provide(new Get);

        self::assertSame(['ThrowingCollector'], $page->degraded);
    }

    #[Test]
    public function a_sample_keeps_its_labels_and_its_value(): void
    {
        $page = $this->provider([$this->collector([
            MetricFamily::gauge('storm_outbox_events_oldest_pending_age_seconds', 'age', [new MetricSample(['partition' => 'p3'], 41.5)]),
        ])])->provide(new Get);

        self::assertSame('age', $page->families[0]['help']);
        self::assertSame(['partition' => 'p3'], $page->families[0]['samples'][0]['labels']);
        self::assertSame(41.5, $page->families[0]['samples'][0]['value']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_collector_that_threw_is_named_rather_than_read_as_an_empty_queue(): void
    {
        // the whole reason `degraded` exists: a block missing because its collector threw looks
        // exactly like a queue that is empty, and an operator would act on the wrong one
        $page = $this->provider([
            $this->collector([MetricFamily::gauge('storm_inbox_rows', 'rows', [new MetricSample([], 7)])]),
            new ThrowingCollector,
        ])->provide(new Get);

        self::assertSame(['storm_inbox_rows'], array_column($page->families, 'name'));
        self::assertSame(['ThrowingCollector'], $page->degraded);
    }

    #[Test]
    public function a_healthy_read_names_nobody_as_degraded(): void
    {
        $page = $this->provider([$this->collector([
            MetricFamily::gauge('storm_inbox_rows', 'rows', [new MetricSample([], 0)]),
        ])])->provide(new Get);

        self::assertSame([], $page->degraded);
    }

    /**
     * @param  list<MetricFamily>  $families
     */
    private function collector(array $families): MetricsCollector
    {
        return new class($families) implements MetricsCollector
        {
            /**
             * @param  list<MetricFamily>  $families
             */
            public function __construct(private readonly array $families) {}

            public function families(): array
            {
                return $this->families;
            }
        };
    }

    /**
     * @param  list<MetricsCollector>  $collectors
     */
    private function provider(array $collectors, bool $anonymous = true): BacklogProvider
    {
        $audit = new OpsAuditLog(new NullLogger);

        return new BacklogProvider(
            // the statement bound is disabled so no connection is touched: this suite judges the
            // selection and the degraded naming, the exposition's own suite holds the bound
            new MetricsExposition($collectors, new PrometheusTextRenderer, $this->createStub(Connection::class), 0),
            new OpsActorGate($audit, null, allowAnonymousReads: $anonymous),
        );
    }
}

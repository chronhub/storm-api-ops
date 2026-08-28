<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\ApiOps\View\ProjectionsView;

final class ProjectionsViewTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_unclaimed_projection_is_not_reported_as_an_expired_lease(): void
    {
        // the distinction this screen exists to keep: nothing ever claimed this projection, which is
        // not a worker that died. Folding the two would send an operator hunting a worker that never
        // existed, and it would inflate the attention block with projections nobody started.
        $html = new ProjectionsView()->render([$this->projection(leaseOwner: null, leaseLive: null)], 0);

        self::assertStringContainsString('unclaimed', $html);
        self::assertStringNotContainsString('expired', $html);
        self::assertStringNotContainsString('class="degraded"', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_expired_lease_is_announced_as_a_worker_that_stopped(): void
    {
        $html = new ProjectionsView()->render([$this->projection(leaseOwner: 'worker-a', leaseLive: false)], 0);

        self::assertStringContainsString('worker-a (expired', $html);
        self::assertStringContainsString('EXPIRED', $html);
        self::assertStringContainsString('their worker stopped without releasing it', $html);
    }

    #[Test]
    public function a_live_lease_carries_its_horizon_and_raises_nothing(): void
    {
        // the verdict AND the horizon: `expired` alone leaves an operator wondering whether the
        // worker died a second ago or an hour ago, which is the difference between waiting and
        // reclaiming
        $html = new ProjectionsView()->render([$this->projection(leaseOwner: 'worker-a', leaseLive: true, leaseUntil: '2026-08-23T10:05:00Z')], 0);

        self::assertStringContainsString('worker-a (live until 2026-08-23T10:05:00Z)', $html);
        self::assertStringNotContainsString('class="degraded"', $html);
    }

    #[Test]
    public function the_stop_reason_keeps_its_three_fields(): void
    {
        // three questions, three answers: when it gave up, what kind of failure, and what it said. A
        // collapsed line reads well and cannot be scanned for a repeated class across projections.
        $html = new ProjectionsView()->render([$this->projection(
            failedAt: '2026-08-23T09:00:00Z',
            errorClass: 'App\\Broken',
            errorMessage: 'the row would not decode',
        )], 0);

        self::assertStringContainsString('2026-08-23T09:00:00Z', $html);
        self::assertStringContainsString('App\\Broken', $html);
        self::assertStringContainsString('the row would not decode', $html);
    }

    #[Test]
    public function a_failure_recorded_without_a_class_says_so_rather_than_showing_a_blank(): void
    {
        $html = new ProjectionsView()->render([$this->projection(failedAt: '2026-08-23T09:00:00Z')], 0);

        self::assertStringContainsString('(no class recorded)', $html);
        self::assertStringContainsString('(no message recorded)', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_failed_projection_is_announced_above_the_table(): void
    {
        $html = new ProjectionsView()->render([$this->projection(failedAt: '2026-08-23T09:00:00Z')], 0);

        $noticeAt = strpos($html, 'stopped on a failure');
        $tableAt = strpos($html, '<table>');

        self::assertIsInt($noticeAt);
        self::assertIsInt($tableAt);
        self::assertLessThan($tableAt, $noticeAt);
    }

    #[Test]
    public function a_healthy_page_raises_nothing_at_all(): void
    {
        $html = new ProjectionsView()->render([$this->projection(leaseOwner: 'worker-a', leaseLive: true)], 0);

        self::assertStringNotContainsString('class="degraded"', $html);
        self::assertStringContainsString('<table>', $html);
    }

    #[Test]
    public function an_empty_registry_says_what_empty_means_here(): void
    {
        // an operator must not read this as "nothing is running": it lists what was DECLARED
        $html = new ProjectionsView()->render([], 0);

        self::assertStringContainsString('nothing was declared', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    #[Test]
    public function every_projection_reaches_the_table_not_only_the_first(): void
    {
        $html = new ProjectionsView()->render([$this->projection(name: 'rm_one'), $this->projection(name: 'rm_two')], 0);

        self::assertStringContainsString('rm_one', $html);
        self::assertStringContainsString('rm_two', $html);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stored_error_message_cannot_carry_markup_into_the_page(): void
    {
        $html = new ProjectionsView()->render([$this->projection(
            failedAt: '2026-08-23T09:00:00Z',
            errorMessage: '<script>alert(1)</script>',
        )], 0);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    private function projection(
        string $name = 'rm_account_balance',
        ?string $leaseOwner = null,
        ?bool $leaseLive = null,
        ?string $leaseUntil = null,
        ?string $failedAt = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): ProjectionResource {
        return new ProjectionResource(
            name: $name,
            status: 'running',
            pauseUntil: null,
            position: 42,
            lag: 3,
            atHead: false,
            mode: 'persistent',
            categories: [],
            eventClasses: [],
            sourceStream: null,
            sourceRevision: 0,
            leaseOwner: $leaseOwner,
            leaseLive: $leaseLive,
            leaseUntil: $leaseUntil,
            heartbeatAt: null,
            targetStream: null,
            targetPrefix: null,
            generation: 1,
            failedAt: $failedAt,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
        );
    }
}

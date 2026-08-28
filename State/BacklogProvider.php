<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Override;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\Resource\BacklogResource;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsExposition;

use function array_map;
use function in_array;
use function is_string;

/**
 * The backlog families as JSON, read through the metrics exposition rather than around it.
 *
 * Going through it is the point: the exposition bounds each collector with a per-scrape statement
 * timeout and turns a collector that throws into a named error rather than a dead read. A provider
 * walking the collectors itself would have to copy both, and a copy of a bound is free to drift
 * into an ops read that hangs.
 *
 * @implements ProviderInterface<BacklogResource>
 */
final readonly class BacklogProvider implements ProviderInterface
{
    /**
     * The families that answer "what is waiting, and since when". Deliberately a NAMED list and not
     * a prefix rule: a prefix would silently adopt every future family whose name happens to start
     * the same way, and this endpoint would drift into the JSON metrics dump it exists not to be.
     */
    public const array FAMILIES = [
        'storm_outbox_events',
        'storm_outbox_events_pending_partitions',
        'storm_outbox_events_cooling',
        'storm_outbox_events_oldest_pending_age_seconds',
        'storm_inbox_rows',
        'storm_saga_outbox',
        'storm_saga_outbox_oldest_pending_age_seconds',
    ];

    public function __construct(
        private MetricsExposition $exposition,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AnonymousReadRefused when no actor is bound and the app did not opt out of the read gate
     */
    #[Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BacklogResource
    {
        $this->gate->assertOwnedIdentityForRead('backlog.read', '*');

        $families = [];
        $degraded = [];

        foreach ($this->exposition->collectFamilies() as $family) {
            // no early exit in this loop: the families arrive in collector order and the error block
            // is appended last, so any `continue` here would be indistinguishable from a `break` on
            // most inputs while stopping the read on some others
            if ($family->name === MetricsExposition::ERRORS_FAMILY) {
                $degraded = $this->degradedFrom($family);
            } elseif (in_array($family->name, self::FAMILIES, true)) {
                $families[] = [
                    'name' => $family->name,
                    'help' => $family->help,
                    'samples' => array_map(static fn (MetricSample $sample): array => [
                        'labels' => $sample->labels,
                        'value' => $sample->value,
                    ], $family->samples),
                ];
            }
        }

        return new BacklogResource($families, $degraded);
    }

    /**
     * @return list<string>
     */
    private function degradedFrom(MetricFamily $errors): array
    {
        $names = [];

        foreach ($errors->samples as $sample) {
            $collector = $sample->labels['collector'] ?? null;

            // a degraded read names the collector, never the count: an operator acts on which block
            // is missing, and two failures of one collector are still one absent block
            if (is_string($collector) && ! in_array($collector, $names, true)) {
                $names[] = $collector;
            }
        }

        return $names;
    }
}

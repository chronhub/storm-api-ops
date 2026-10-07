<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\MalformedQueryParameter;
use Storm\ApiOps\State\CorrelationEventsProvider;
use Storm\ApiOps\State\CorrelationIdSet;
use Storm\ApiOps\State\PageWindow;

/**
 * A bounded correlation trace with an explicit indication that matching events remain beyond it.
 *
 * Events retain their stored global order and payload veil. `truncated` describes this read's
 * window, not the completeness of a business operation or an inferred saga lineage.
 */
#[ApiResource(
    shortName: 'StormCorrelationEventsPage',
    operations: [
        new Get(
            uriTemplate: '/_storm/correlations/events',
            provider: CorrelationEventsProvider::class,
            parameters: [
                'ids' => new QueryParameter(schema: ['type' => 'string'], description: 'One to '.CorrelationIdSet::MAX_IDS.' correlation ids, comma-separated. A wider set is refused rather than narrowed.'),
                'limit' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => PageWindow::MAX_LIMIT], description: 'Maximum events returned; truncated reports whether another matching event exists.'),
            ],
        ),
    ],
    openapi: false,
    exceptionToStatus: [
        AnonymousReadRefused::class => 403,
        MalformedQueryParameter::class => 422,
    ],
)]
final readonly class CorrelationEventsPageResource
{
    /**
     * @param  list<StoredEventResource>  $events  at most `$limit` events, in global position order
     * @param  positive-int  $limit  the applied server-side cap
     */
    public function __construct(
        /**
         * @infection-ignore-all read by the API Platform serializer alone, which embeds each event
         *                       instead of linking its IRI; no unit test boots that serializer, and
         *                       no integration test reads this page
         */
        #[ApiProperty(readableLink: true)]
        public array $events,
        public int $limit,
        public bool $truncated,
    ) {}
}

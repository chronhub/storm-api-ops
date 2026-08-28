<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use LogicException;
use Storm\Contracts\Aggregate\AggregateIdentity;
use Storm\Contracts\Aggregate\AggregateRoot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Category-to-aggregate resolution for the ops surface, from the same `storm.aggregates` config
 * the `AggregateRepositoryManager` builds from. The category is the aggregate's URL identity in
 * `/_storm/aggregates/{category}/{id}`: already unique per aggregate type, already URL-safe, and
 * already the stream lane, so no new naming is invented for HTTP.
 */
final readonly class AggregateCatalog
{
    /** @var array<string, array{class: class-string<AggregateRoot<AggregateIdentity>>, id: class-string<AggregateIdentity>}> */
    private array $byCategory;

    /**
     * @param  array<class-string<AggregateRoot<AggregateIdentity>>, array{id: class-string<AggregateIdentity>, category: string, snapshot?: array{threshold: int}}>  $aggregates
     *
     * @throws LogicException when two aggregates declare the same category; last-wins would answer
     *                        `/_storm/aggregates/{category}/{id}` for the WRONG aggregate class,
     *                        and this catalog is the one place that can notice the collision
     */
    public function __construct(
        #[Autowire('%storm.aggregates%')]
        array $aggregates,
    ) {
        $byCategory = [];
        foreach ($aggregates as $class => $config) {
            if (isset($byCategory[$config['category']])) {
                throw new LogicException(sprintf(
                    'Aggregates "%s" and "%s" both declare category "%s" — the category is the URL identity of /_storm/aggregates/{category}/{id}, and a duplicate would silently answer for whichever declared last.',
                    $byCategory[$config['category']]['class'],
                    $class,
                    $config['category'],
                ));
            }

            $byCategory[$config['category']] = ['class' => $class, 'id' => $config['id']];
        }

        $this->byCategory = $byCategory;
    }

    /**
     * The aggregate class and identity class behind a category, or null when no aggregate is
     * declared under it. The null is the provider's 404, never an exception: an unknown category
     * is a lookup miss for an ops browser, not a caller fault to translate.
     *
     * @return array{class: class-string<AggregateRoot<AggregateIdentity>>, id: class-string<AggregateIdentity>}|null
     */
    public function entryFor(string $category): ?array
    {
        return $this->byCategory[$category] ?? null;
    }
}

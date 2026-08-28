<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\AggregateCatalog;

final class AggregateCatalogTest extends TestCase
{
    #[Test]
    public function a_declared_category_resolves_to_its_class_and_identity(): void
    {
        // @phpstan-ignore argument.type (fixture FQCNs; the catalog never loads the classes)
        $catalog = new AggregateCatalog([
            'App\\Account' => ['id' => 'App\\AccountId', 'category' => 'account'],
        ]);

        $this->assertSame(['class' => 'App\\Account', 'id' => 'App\\AccountId'], $catalog->entryFor('account'));
        $this->assertNull($catalog->entryFor('order'));
    }

    #[Test]
    #[Group('adversarial')]
    public function two_aggregates_sharing_a_category_refuse_at_construction(): void
    {
        // last-wins would answer /_storm/aggregates/{category}/{id} for the WRONG aggregate class,
        // silently; the catalog is the one place that can notice the configuration fault
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/both declare category "account"/');

        // @phpstan-ignore argument.type (fixture FQCNs; the catalog never loads the classes)
        new AggregateCatalog([
            'App\\Account' => ['id' => 'App\\AccountId', 'category' => 'account'],
            'App\\LegacyAccount' => ['id' => 'App\\AccountId', 'category' => 'account'],
        ]);
    }
}

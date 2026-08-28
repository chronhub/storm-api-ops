<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\Fixture;

use Override;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

use function in_array;
use function sprintf;

/**
 * A generator that answers a prefixed path for the routes it knows and refuses the rest, which is
 * the shape an application produces when it imports a subset of this surface.
 */
final class StubUrlGenerator implements UrlGeneratorInterface
{
    private RequestContext $context;

    /**
     * @param  list<string>  $known  route names this application imported
     */
    public function __construct(private readonly array $known, private readonly string $prefix = '/api')
    {
        $this->context = new RequestContext;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[Override]
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        if (! in_array($name, $this->known, true)) {
            throw new RouteNotFoundException(sprintf('no route "%s"', $name));
        }

        return $this->prefix.'/_storm/view/'.$name;
    }

    #[Override]
    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    #[Override]
    public function getContext(): RequestContext
    {
        return $this->context;
    }
}

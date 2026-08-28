<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The framework's own introspection surface over HTTP: HTTP twins of the `storm:*` console
 * commands for streams/events, projections, sagas, and the aggregate as fold/version/history. It
 * rides the same API Platform bridge under the ops zone at `/_storm/*`, firewalled app-side, with
 * Bureau as the identity substrate.
 *
 * A deliberate TOP-CONSUMER sibling of the strict-leaf Api module: introspection reads the
 * packages' own services, the same stores and gateways the console commands render, which the Api
 * leaf must never see; widening the leaf would kill its bus-only guarantee. The surface lives here
 * instead, and installing THIS package is the conscious opt-in to what it carries: raw event
 * payloads and destructive actions. Registered next to `StormApiBundle`, never instead of it.
 */
final class StormApiOpsBundle extends AbstractBundle
{
    /**
     * {@inheritDoc}
     *
     * Registering the bundle IS the exposure gesture: the ops resources join API Platform's scan
     * by prepend, so the app never lists a framework path in its own `mapping.paths`; removing the
     * bundle removes the whole surface, nothing lingers by configuration.
     */
    #[Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('api_platform', [
            'mapping' => ['paths' => [__DIR__.'/Resource']],
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * The one knob is a fail-closed default: mutations refuse an anonymous caller unless the app
     * opts out in so many words, a dev/demo gesture, never a production posture.
     */
    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->booleanNode('allow_anonymous_mutations')
            ->defaultFalse()
            ->info('Let the destructive POST verbs through without a Bureau-bound actor. The default refuses with a 403: the audit trail names who acted, and an anonymous mutation would blank that line.')
            ->end()
            ->booleanNode('allow_anonymous_reads')
            ->defaultFalse()
            ->info('Let the GET surface through without a Bureau-bound actor; describe stays open either way. The default refuses with a 403: the reads serve hydrated event payloads, and a forgotten firewall pattern must fail as loud here as on the mutations.')
            ->end()
            ->end();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set(
            'storm_api_ops.allow_anonymous_mutations',
            (bool) ($config['allow_anonymous_mutations'] ?? false),
        );
        $container->parameters()->set(
            'storm_api_ops.allow_anonymous_reads',
            (bool) ($config['allow_anonymous_reads'] ?? false),
        );

        $container->import(__DIR__.'/config/services.php');
    }
}

<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Storm\ApiOps\EventDispatcherOpsAuditDegradationObserver;
use Storm\ApiOps\OpsAuditDegradationObserver;

/*
 * ApiOps package wiring.
 *
 * Registers the introspection services with autowiring and autoconfiguration: the read providers
 * ride API Platform's autoconfiguration exactly like the bridge's own, and the AggregateCatalog
 * resolves its `storm.aggregates` parameter through its Autowire attribute. The resource DTOs and
 * the static window arithmetic are data, not services; they are excluded like the bridge excludes
 * its own.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Storm\\ApiOps\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Resource/', // resource DTOs, built by the providers, not services
            dirname(__DIR__).'/State/PageWindow.php', // static window arithmetic, not a service
            dirname(__DIR__).'/OpsAuditDegraded.php', // event payload, built by the observer
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
        ]);

    // audit degradation reaches the app's event dispatcher; a host listener decides what it becomes
    $services->alias(OpsAuditDegradationObserver::class, EventDispatcherOpsAuditDegradationObserver::class);
};

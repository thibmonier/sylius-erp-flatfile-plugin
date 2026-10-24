<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusErpFlatfilePlugin\Command\ExportOrdersCommand;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\AtomicFileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\FileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Export\DbalExportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Export\DoctrinePendingOrderEvents;
use Tmonier\SyliusErpFlatfilePlugin\Export\ExportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Export\OrderExporter;
use Tmonier\SyliusErpFlatfilePlugin\Export\PendingOrderEvents;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(DoctrinePendingOrderEvents::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            param('tmonier_webhook.model.outbox_event.class'),
            param('tmonier_erp_flatfile.event_types'),
        ])
    ;
    $services->alias(PendingOrderEvents::class, DoctrinePendingOrderEvents::class);

    $services->set(DbalExportJournal::class)->args([service('doctrine.dbal.default_connection')]);
    $services->alias(ExportJournal::class, DbalExportJournal::class);

    $services->set(AtomicFileDepositor::class)->args([param('tmonier_erp_flatfile.exchange_directory')]);
    $services->alias(FileDepositor::class, AtomicFileDepositor::class);

    $services
        ->set(OrderExporter::class)
        ->args([
            service(PendingOrderEvents::class),
            service(ExportJournal::class),
            service(FileDepositor::class),
            service('clock'),
            service('logger'),
            param('tmonier_erp_flatfile.batch_size'),
        ])
        ->public()
    ;

    $services
        ->set(ExportOrdersCommand::class)
        ->args([service(OrderExporter::class)])
        ->tag('console.command')
    ;
};

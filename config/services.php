<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusErpFlatfilePlugin\Command\ExportOrdersCommand;
use Tmonier\SyliusErpFlatfilePlugin\Command\ImportBatchesCommand;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\AtomicFileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\FileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Export\DbalExportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Export\DoctrinePendingOrderEvents;
use Tmonier\SyliusErpFlatfilePlugin\Export\ExportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Export\OrderExporter;
use Tmonier\SyliusErpFlatfilePlugin\Export\PendingOrderEvents;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchImporter;
use Tmonier\SyliusErpFlatfilePlugin\Import\CatalogLookup;
use Tmonier\SyliusErpFlatfilePlugin\Import\CatalogUpdates;
use Tmonier\SyliusErpFlatfilePlugin\Import\DbalImportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Import\DoctrineCatalog;
use Tmonier\SyliusErpFlatfilePlugin\Import\ExchangeDirectoryImporter;
use Tmonier\SyliusErpFlatfilePlugin\Import\ImportJournal;

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

    // Import of prices (per channel) and stock (on hand): ADR 0017.
    $services
        ->set(DoctrineCatalog::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('sylius.factory.channel_pricing'),
            service('sylius.repository.product_variant'),
            service('sylius.repository.channel'),
        ])
    ;
    $services->alias(CatalogLookup::class, DoctrineCatalog::class);
    $services->alias(CatalogUpdates::class, DoctrineCatalog::class);

    $services->set(DbalImportJournal::class)->args([service('doctrine.dbal.default_connection'), service('clock')]);
    $services->alias(ImportJournal::class, DbalImportJournal::class);

    $services
        ->set(BatchImporter::class)
        ->args([service(CatalogLookup::class), service(CatalogUpdates::class), service(ImportJournal::class)])
        ->public()
    ;

    $services
        ->set(ExchangeDirectoryImporter::class)
        ->args([param('tmonier_erp_flatfile.exchange_directory'), service(BatchImporter::class)])
        ->public()
    ;

    $services
        ->set(ImportBatchesCommand::class)
        ->args([service(ExchangeDirectoryImporter::class)])
        ->tag('console.command')
    ;
};

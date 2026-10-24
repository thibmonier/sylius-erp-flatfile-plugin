<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusErpFlatfilePlugin\DependencyInjection\TmonierSyliusErpFlatfileExtension;
use Tmonier\SyliusErpFlatfilePlugin\Export\OrderExporter;

final class TmonierSyliusErpFlatfileExtensionTest extends TestCase
{
    public function testTheDefaultsExportThePlacedOrdersInBatchesOf500(): void
    {
        $container = $this->load([]);

        self::assertSame('%kernel.project_dir%/var/erp-exchange', $container->getParameter('tmonier_erp_flatfile.exchange_directory'));
        self::assertSame(500, $container->getParameter('tmonier_erp_flatfile.batch_size'));
        self::assertSame(['order.placed'], $container->getParameter('tmonier_erp_flatfile.event_types'));
        self::assertTrue($container->hasDefinition(OrderExporter::class));
    }

    public function testTheExchangeDirectoryAndTheBatchSizeAreConfigurable(): void
    {
        $container = $this->load(['exchange_directory' => '/srv/exchange', 'batch_size' => 50]);

        self::assertSame('/srv/exchange', $container->getParameter('tmonier_erp_flatfile.exchange_directory'));
        self::assertSame(50, $container->getParameter('tmonier_erp_flatfile.batch_size'));
    }

    public function testAnEmptyBatchIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['batch_size' => 0]);
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new TmonierSyliusErpFlatfileExtension())->load([$config], $container);

        return $container;
    }
}

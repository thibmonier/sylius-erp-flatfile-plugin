<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class TmonierSyliusErpFlatfileExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function getAlias(): string
    {
        return 'tmonier_sylius_erp_flatfile';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{exchange_directory: string, batch_size: int, event_types: list<string>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('tmonier_erp_flatfile.exchange_directory', $config['exchange_directory']);
        $container->setParameter('tmonier_erp_flatfile.batch_size', $config['batch_size']);
        $container->setParameter('tmonier_erp_flatfile.event_types', $config['event_types']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusErpFlatfilePlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusErpFlatfilePlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusErpFlatfilePlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusErpFlatfilePlugin/src/Migrations';
    }

    /** @return list<string> */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}

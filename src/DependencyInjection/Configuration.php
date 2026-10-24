<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_erp_flatfile');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('exchange_directory')
                    ->info('Directory the ERP reads. It must exist and be writable by the process that runs the export.')
                    ->defaultValue('%kernel.project_dir%/var/erp-exchange')
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('batch_size')
                    ->info('Maximum number of orders per file.')
                    ->defaultValue(500)
                    ->min(1)
                ->end()
                ->arrayNode('event_types')
                    ->info('Outbox event types that are exported. order.placed is the order sent to the ERP; the contract has no event type column, so each type adds its own lines for the same order.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(['order.placed'])
                    ->requiresAtLeastOneElement()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

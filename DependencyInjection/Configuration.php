<?php

namespace Kitano\Bundle\PaymentCmcicBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration processer.
 * Parses/validates the extension configuration and sets default values.
 *
 * @author Benjamin Dulau <benjamin.dulau@anonymation.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree.
     *
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('kitano_payment_cmcic');

        $this->addConfigSection($rootNode);

        return $treeBuilder;
    }

    /**
     * Parses the kitano_payment_cmcic config section
     * Example for yaml driver:
     * kitano_payment_cmcic:
     *     config:
     *         version:
     *         tpe:
     *         key:
     *         lang:
     *         email:
     *         company_code:
     *         return_route:
     *         return_route_ok:
     *         return_route_ko:
     *
     * @param ArrayNodeDefinition $node
     * @return void
     */
    private function addConfigSection(ArrayNodeDefinition $node)
    {
        $node
            ->children()
                ->arrayNode('config')
                    ->children()
                        ->scalarNode('version')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('tpe')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('lang')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('sandbox')->defaultValue(false)->end()
                        ->arrayNode('url')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('production')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('authorize')->isRequired()->defaultValue('https//paiement.creditmutuel.fr/paiement.cgi')->end()
                                        ->scalarNode('capture')->isRequired()->defaultValue('https//paiement.creditmutuel.fr/capture_paiement.cgi')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('sandbox')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('authorize')->isRequired()->defaultValue('https://ssl.paiement.cic-banques.fr/test/paiement.cgi')->end()
                                        ->scalarNode('capture')->isRequired()->defaultValue('https://ssl.paiement.cic-banques.fr/test/capture_paiement.cgi')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('email')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('company_code')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('return_route')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('return_route_ok')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('return_route_ko')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('certificate_path')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end();
    }
}
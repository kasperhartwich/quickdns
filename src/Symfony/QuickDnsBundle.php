<?php

declare(strict_types=1);

namespace QuickDns\Symfony;

use QuickDns\QuickDns;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers QuickDns as a lazy, autowirable service. Configure it in config/packages/quickdns.yaml:
 *
 *     quickdns:
 *         email: '%env(QUICKDNS_EMAIL)%'
 *         password: '%env(QUICKDNS_PASSWORD)%'
 *         # client: my_guzzle_client   # optional service id of a GuzzleHttp\ClientInterface
 */
class QuickDnsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'quickdns';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('email')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('password')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('client')->defaultNull()->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(QuickDns::class)
                ->factory([QuickDns::class, 'lazy'])
                ->args([$config['email'], $config['password'], $config['client'] ? service($config['client']) : null])
            ->alias('quickdns', QuickDns::class);
    }
}

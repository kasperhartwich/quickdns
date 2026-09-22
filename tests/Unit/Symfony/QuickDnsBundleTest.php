<?php

namespace QuickDns\Tests\Unit\Symfony;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuickDns\QuickDns;
use QuickDns\Symfony\QuickDnsBundle;
use QuickDns\Testing\FakeQuickDns;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class QuickDnsBundleTest extends TestCase
{
    private function container(array $config, ?Client $client = null): ContainerBuilder
    {
        // What a kernel provides; older Symfony versions read it while loading bundle config.
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new QuickDnsBundle();
        $bundle->getContainerExtension()->load([$config], $container);
        if ($client) {
            $container->setDefinition('test.client', (new Definition(Client::class))->setSynthetic(true)->setPublic(true));
        }
        $container->setAlias('test.quickdns', QuickDns::class)->setPublic(true);
        $container->compile();
        if ($client) {
            $container->set('test.client', $client);
        }

        return $container;
    }

    public function test_registers_a_lazy_quickdns()
    {
        // Building the service must not log in: nothing may reach quickdns.dk here.
        $quickDns = $this->container(['email' => 'test@example.dk', 'password' => 'secret'])->get('test.quickdns');

        $this->assertInstanceOf(QuickDns::class, $quickDns);
    }

    public function test_uses_the_configured_client_service()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $client = new Client(['handler' => HandlerStack::create($fake)]);

        $container = $this->container(['email' => 'test@example.dk', 'password' => 'secret', 'client' => 'test.client'], $client);

        $this->assertSame('flyvende-agurk-pingvin.dk', $container->get('test.quickdns')->getZones()[0]->domain);
    }

    #[DataProvider('incompleteConfig')]
    public function test_email_and_password_are_required(array $config)
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->container($config);
    }

    public static function incompleteConfig(): array
    {
        return [
            'no password' => [['email' => 'test@example.dk']],
            'no email' => [['password' => 'secret']],
            'empty email' => [['email' => '', 'password' => 'secret']],
        ];
    }

    public function test_extension_alias()
    {
        $this->assertSame('quickdns', (new QuickDnsBundle())->getContainerExtension()->getAlias());
    }
}

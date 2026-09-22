<?php

namespace QuickDns\Tests\Unit\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Orchestra\Testbench\TestCase;
use QuickDns\Laravel\Facades\QuickDns as QuickDnsFacade;
use QuickDns\Laravel\QuickDnsServiceProvider;
use QuickDns\QuickDns;
use QuickDns\Testing\FakeQuickDns;
use QuickDns\Zone;

final class QuickDnsServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [QuickDnsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('quickdns.email', 'test@example.dk');
        $app['config']->set('quickdns.password', 'secret');
    }

    public function test_binds_a_lazy_singleton()
    {
        // Resolving must not log in: nothing may reach quickdns.dk here.
        $quickDns = $this->app->make(QuickDns::class);

        $this->assertInstanceOf(QuickDns::class, $quickDns);
        $this->assertSame($quickDns, $this->app->make('quickdns'));
    }

    public function test_uses_the_configured_client_binding()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $this->app->instance('quickdns.test-client', new Client(['handler' => HandlerStack::create($fake)]));
        $this->app['config']->set('quickdns.client', 'quickdns.test-client');

        $zones = $this->app->make(QuickDns::class)->getZones();

        $this->assertSame('flyvende-agurk-pingvin.dk', $zones[0]->domain);
    }

    public function test_config_defaults_come_from_env()
    {
        // phpunit.xml sets both to "" in $_ENV and $_SERVER, which env() reads first.
        $saved = [$_ENV, $_SERVER];
        $_ENV['QUICKDNS_EMAIL'] = $_SERVER['QUICKDNS_EMAIL'] = 'env@example.dk';
        $_ENV['QUICKDNS_PASSWORD'] = $_SERVER['QUICKDNS_PASSWORD'] = 'env-secret';
        try {
            $config = require __DIR__.'/../../../config/quickdns.php';
        } finally {
            [$_ENV, $_SERVER] = $saved;
        }

        $this->assertSame(['email' => 'env@example.dk', 'password' => 'env-secret', 'client' => null], $config);
    }

    public function test_config_can_be_published()
    {
        $paths = QuickDnsServiceProvider::pathsToPublish(QuickDnsServiceProvider::class, 'quickdns-config');

        $this->assertSame([$this->app->configPath('quickdns.php')], array_values($paths));
        $this->assertFileExists(array_key_first($paths));
    }

    public function test_facade_fake()
    {
        $fake = QuickDnsFacade::fake();
        $fake->addTemplate('standard');

        $zone = (new Zone(QuickDnsFacade::getFacadeRoot(), 'flyvende-agurk-pingvin.dk'))->create();
        QuickDnsFacade::getTemplate('standard')->addZone($zone);

        $this->assertSame(['standard'], $fake->templatesOf('flyvende-agurk-pingvin.dk'));
        $this->assertCount(1, QuickDnsFacade::getZones());
    }
}

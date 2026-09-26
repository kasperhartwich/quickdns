<?php

declare(strict_types=1);

namespace QuickDns\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use QuickDns\QuickDns;

/**
 * Registers QuickDns as a lazy singleton configured from config/quickdns.php. Laravel discovers it
 * through composer.json, so installing the package is enough.
 */
class QuickDnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/quickdns.php', 'quickdns');

        $this->app->singleton(QuickDns::class, function (Application $app) {
            $config = $app['config']['quickdns'];

            return new QuickDns(
                (string) $config['email'],
                (string) $config['password'],
                $config['client'] ? $app->make($config['client']) : null,
            );
        });
        $this->app->alias(QuickDns::class, 'quickdns');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/quickdns.php' => $this->app->configPath('quickdns.php'),
        ], 'quickdns-config');
    }
}

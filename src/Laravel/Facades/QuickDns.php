<?php

declare(strict_types=1);

namespace QuickDns\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use QuickDns\Testing\FakeQuickDns;

/**
 * @method static \QuickDns\Zone[] getZones()
 * @method static \QuickDns\Zone getZone(string $domain)
 * @method static \QuickDns\Template[] getTemplates()
 * @method static \QuickDns\Template getTemplate(string $name)
 * @method static \QuickDns\Group[] getGroups()
 * @method static \QuickDns\Group getGroup(string $name)
 * @method static \QuickDns\Record[] getRecords(\QuickDns\Zone|int|string $zone)
 *
 * @see \QuickDns\QuickDns
 */
class QuickDns extends Facade
{
    /**
     * Swap the bound QuickDns for one talking to an in-memory FakeQuickDns, and return the fake to
     * set up state and make assertions on.
     */
    public static function fake(): FakeQuickDns
    {
        $fake = new FakeQuickDns('test@example.dk', 'secret');
        static::swap(\QuickDns\QuickDns::lazy('test@example.dk', 'secret', $fake->client()));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \QuickDns\QuickDns::class;
    }
}

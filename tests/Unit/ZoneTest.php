<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;
use QuickDns\Zone;

final class ZoneTest extends TestCase
{
    public function test_create()
    {
        $quickDns = $this->quickDns(['addzone-ok']);

        $zone = (new Zone($quickDns, 'flyvende-agurk-pingvin.dk'))->create();

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertSame('addzone?zone=flyvende-agurk-pingvin.dk&getdata=0', $this->lastRequestUri());
        $this->assertSame('17286', $zone->id);
    }

    public function test_create_with_data()
    {
        (new Zone($this->quickDns(['addzone-ok']), 'flyvende-agurk-pingvin.dk'))->create(true);

        $this->assertSame('addzone?zone=flyvende-agurk-pingvin.dk&getdata=1', $this->lastRequestUri());
    }

    public function test_create_already_exists()
    {
        $zone = new Zone($this->quickDns(['addzone-exists']), 'flyvende-agurk-pingvin.dk');

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Zonen eksisterer allerede');
        $zone->create();
    }

    public function test_delete()
    {
        $zone = new Zone($this->quickDns(['delzone']), 'flyvende-agurk-pingvin.dk');
        $zone->id = 17287;

        $this->assertTrue($zone->delete());
        $this->assertSame('delzone?id=17287', $this->lastRequestUri());
    }

    public function test_delete_without_id()
    {
        $zone = new Zone($this->quickDns(), 'flyvende-agurk-pingvin.dk');

        $this->expectException(\BadFunctionCallException::class);
        $this->expectExceptionMessage('Zone is not created yet.');
        $zone->delete();
    }

    public function test_create_then_delete_without_fetching()
    {
        $zone = (new Zone($this->quickDns(['addzone-ok', 'delzone']), 'flyvende-agurk-pingvin.dk'))->create();

        $this->assertTrue($zone->delete());
        $this->assertSame('delzone?id=17286', $this->lastRequestUri());
    }
}

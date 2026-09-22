<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;
use QuickDns\Group;
use QuickDns\Zone;

final class GroupTest extends TestCase
{
    public function test_create()
    {
        $group = (new Group($this->quickDns(['addgroup-ok']), 'sjaskende-rabarber'))->create();

        $this->assertInstanceOf(Group::class, $group);
        $this->assertSame('addgroup?group=sjaskende-rabarber', $this->lastRequestUri());
        $this->assertNull($group->id);
    }

    public function test_create_already_exists()
    {
        $group = new Group($this->quickDns(['addgroup-exists']), 'sjaskende-rabarber');

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Gruppen eksisterer allerede');
        $group->create();
    }

    public function test_delete()
    {
        $group = new Group($this->quickDns(['delgroup']), 'sjaskende-rabarber');
        $group->id = 744;

        $this->assertTrue($group->delete());
        $this->assertSame('delgroup?id=744', $this->lastRequestUri());
    }

    public function test_delete_unknown()
    {
        $group = new Group($this->quickDns(['delgroup-error']), 'sjaskende-rabarber');
        $group->id = 744;

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Gruppen findes ikke');
        $group->delete();
    }

    public function test_delete_without_id()
    {
        $group = new Group($this->quickDns(), 'sjaskende-rabarber');

        $this->expectException(\BadFunctionCallException::class);
        $this->expectExceptionMessage('Group is not created yet.');
        $group->delete();
    }

    public function test_add_and_remove_zone()
    {
        $quickDns = $this->quickDns(['updategroups', 'updategroups']);
        $group = new Group($quickDns, 'sjaskende-rabarber');
        $group->id = 744;
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;

        $group->addZone($zone);
        $this->assertSame('updategroups?zone=17296&group=744', $this->lastRequestUri());

        $group->removeZone($zone);
        $this->assertSame('updategroups?zone=17296', $this->lastRequestUri());
    }
}

<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;
use QuickDns\Group;
use QuickDns\Zone;

final class GroupTest extends TestCase
{
    public function test_create()
    {
        $group = (new Group($this->quickDns(['addgroup-ok', 'groups']), 'test-group'))->create();

        // QuickDNS does not answer with the id, so the groups page is read for it.
        $this->assertSame('/addgroup', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame('group=test-group', $this->history[1]['request']->getUri()->getQuery());
        $this->assertSame('groups', $this->lastRequestUri());
        $this->assertSame(738, $group->id);
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
        $group = new Group($this->quickDns(['delgroup']), 'sjaskende-rabarber', 744);

        $group->delete();
        $this->assertSame('delgroup?id=744', $this->lastRequestUri());
    }

    public function test_delete_unknown()
    {
        $group = new Group($this->quickDns(['delgroup-error']), 'sjaskende-rabarber', 744);

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Gruppen findes ikke');
        $group->delete();
    }

    public function test_delete_without_id()
    {
        $group = new Group($this->quickDns(), 'sjaskende-rabarber');

        $this->expectException(\QuickDns\Exceptions\MissingId::class);
        $this->expectExceptionMessage('Group is not created yet.');
        $group->delete();
    }

    public function test_add_and_remove_zone_keeps_the_zones_other_groups()
    {
        $quickDns = $this->quickDns([
            $this->zonesPage([], [700]), 'updategroups', $this->zonesPage([], [700, 744]),
            $this->zonesPage([], [700, 744]), 'updategroups', $this->zonesPage([], [700]),
        ]);
        $group = new Group($quickDns, 'sjaskende-rabarber', 744);
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');

        $this->assertSame([700, 744], $group->addZone($zone)->groupIds);
        $this->assertSame('updategroups?zone=17287&group=700&group=744', urldecode($this->requestUri(2)));

        $this->assertSame([700], $group->removeZone($zone)->groupIds);
        $this->assertSame('updategroups?zone=17287&group=700', urldecode($this->requestUri(5)));
    }
}

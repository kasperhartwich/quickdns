<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\Group;
use QuickDns\Template;
use QuickDns\Testing\FakeQuickDns;
use QuickDns\Zone;

final class RelationsTest extends TestCase
{
    private function fake(): FakeQuickDns
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('standard');
        $fake->addTemplate('mail');
        $fake->addTemplate('unused');
        $fake->addGroup('kunder');
        $fake->addGroup('andre');
        $fake->addZone('a.dk', templates: ['standard', 'mail'], groups: ['kunder']);
        $fake->addZone('b.dk', templates: ['standard']);
        $fake->addZone('c.dk');

        return $fake;
    }

    public function test_a_templates_zones()
    {
        $quickDns = $this->fake()->quickDns();

        $zones = $quickDns->getTemplate('standard')->zones();

        $this->assertSame(['a.dk', 'b.dk'], array_map(fn (Zone $zone) => $zone->domain, $zones));
        $this->assertSame([], $quickDns->getTemplate('unused')->zones());
        $this->assertSame(2, $quickDns->getTemplate('standard')->zoneCount);
    }

    public function test_a_zones_templates_and_groups()
    {
        $zone = $this->fake()->quickDns()->getZone('a.dk');

        $this->assertSame(['standard', 'mail'], array_map(fn (Template $template) => $template->name, $zone->templates()));
        $this->assertSame(['kunder'], array_map(fn (Group $group) => $group->name, $zone->groups()));
    }

    public function test_a_zone_not_read_from_the_zones_page_is_looked_up()
    {
        $quickDns = $this->fake()->quickDns();

        $zone = new Zone($quickDns, 'a.dk', 1);

        $this->assertSame(['standard', 'mail'], array_map(fn (Template $template) => $template->name, $zone->templates()));
    }

    public function test_a_templates_groups()
    {
        $templates = str_replace(
            "new Array(), new Array());\">Ingen</a>",
            "new Array(), new Array('738'));\">test-group</a>",
            $this->fixture('templates'),
        );
        $quickDns = $this->quickDns([$templates, 'groups']);

        $groups = $quickDns->getTemplates()[0]->groups();

        $this->assertSame([738], array_map(fn (Group $group) => $group->id, $groups));
    }

    public function test_the_old_zones_property_names_its_replacements()
    {
        $template = $this->fake()->quickDns()->getTemplate('standard');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Template::$zones was removed in 3.0: use $zoneCount for the number, or zones() for the zones themselves.');
        $template->zones;
    }
}

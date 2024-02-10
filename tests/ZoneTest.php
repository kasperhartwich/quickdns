<?php

namespace QuickDns\Tests;

use QuickDns\Zone;

class ZoneTest extends TestCase
{
    public function test_create_success()
    {
        $zone = (new Zone($this->quickDns, $this->testDomain))->create();
        $this->assertSame(Zone::class, get_class($zone));
    }

    public function test_get_zones()
    {
        $zones = $this->quickDns->getZones();
        $this->assertIsArray($zones);
        $this->assertSame(array_shift($zones)->domain, $this->testDomain);
    }

    public function test_add_to_template_success()
    {
        $template = $this->quickDns->getTemplate($this->testTemplate);

        $zone = $this->quickDns->getZone($this->testDomain);
        $template->addZone($zone);

        $zone = $this->quickDns->getZone($this->testDomain);
        $this->assertSame($template->name, array_pop($zone->templates));
    }

    public function test_remove_from_template_success()
    {
        $template = $this->quickDns->getTemplate($this->testTemplate);
        $zone = $this->quickDns->getZone($this->testDomain);
        $template->removeZone($zone);

        $zone = $this->quickDns->getZone($this->testDomain);
        $this->assertEmpty($zone->templates);
    }

    public function test_add_to_group_success()
    {
        $group = $this->quickDns->getGroup($this->testGroup);
        $zone = $this->quickDns->getZone($this->testDomain);
        $group->addZone($zone);

        $zone = $this->quickDns->getZone($this->testDomain);
        $this->assertSame($group->name, array_pop($zone->groups));
    }

    public function test_remove_from_group_success()
    {
        $group = $this->quickDns->getGroup($this->testGroup);
        $zone = $this->quickDns->getZone($this->testDomain);
        $group->removeZone($zone);

        $zone = $this->quickDns->getZone($this->testDomain);
        $this->assertEmpty($zone->groups);
    }

    public function test_create_already_exists()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zonen eksisterer allerede');

        (new Zone($this->quickDns, $this->testDomain))->create();
    }

    public function test_create_illegal_domain_name()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zonens navn er ugyldigt');

        (new Zone($this->quickDns, str_replace('.dk', '.invalid', $this->testDomain)))->create();
    }

    public function test_find_deleted_zone_fail()
    {
        $this->quickDns->getZone('quickdns-api-test-domain.dk')->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown domain');
        $this->quickDns->getZone('quickdns-api-test-domain.dk');
    }
}

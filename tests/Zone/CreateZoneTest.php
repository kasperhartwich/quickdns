<?php

namespace Tests\Zone;

use QuickDns\QuickDns;
use QuickDns\Zone;
use Tests\TestCase;

class CreateZoneTest extends TestCase
{
    /** @var QuickDns */
    protected $quickDns;

    protected $testGroup = 'test-group'; // Need to exist before running tests

    protected $testTemplate = 'test-template';  // Need to exist before running tests

    protected $testDomain = 'quickdns-api-test-domain.dk';

    public function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        $this->quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
    }

    public function test_create_success()
    {
        $zone = new Zone($this->quickDns, $this->testDomain);
        $zone->create();
        $this->assertSame(Zone::class, get_class($zone));
    }

    public function test_get_zones()
    {
        $zones = $this->quickDns->getZones();
        $this->assertIsArray($zones);
        $this->assertSame($zones[0]->domain, $this->testDomain);
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

        $zone = new Zone($this->quickDns, $this->testDomain);
        $zone->create();
    }

    public function test_create_illegal_domain_name()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zonens navn er ugyldigt');

        $zone = new Zone($this->quickDns, str_replace('.dk', '.invalid', $this->testDomain));
        $zone->create();
    }
}

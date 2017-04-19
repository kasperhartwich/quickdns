<?php
namespace Tests\Zone;

use Tests\TestCase;
use QuickDns\QuickDns;
use QuickDns\Zone;

class CreateZoneTest extends TestCase
{
    public function testCreateSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $zone = new Zone($quickDns, 'quickdns-api-test-domain.dk');
        $zone->create();
        $this->assertEquals(Zone::class, get_class($zone));
    }

    public function testAddToTemplateSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $template = $quickDns->getTemplate('test-template');
        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $template->addZone($zone);

        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $this->assertEquals($template->name, array_pop($zone->templates));
    }

    public function testRemoveFromTemplateSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $template = $quickDns->getTemplate('test-template');
        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $template->removeZone($zone);

        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $this->assertEmpty($zone->templates);
    }

    public function testAddToGroupSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $group = $quickDns->getGroup('test-group');
        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $group->addZone($zone);

        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $this->assertEquals($group->name, array_pop($zone->groups));
    }

    public function testRemoveFromGroupSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $group = $quickDns->getGroup('test-group');
        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $group->removeZone($zone);

        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $this->assertEmpty($zone->groups);
    }

    public function testCreateAlreadyExists()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zonen eksisterer allerede');

        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $zone = new Zone($quickDns, 'quickdns-api-test-domain.dk');
        $zone->create();
    }

    public function testCreateIllegalDomainName()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zonens navn er ugyldigt');

        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $zone = new Zone($quickDns, 'quickdns-api-test-domain.invalid');
        $zone->create();
    }
}


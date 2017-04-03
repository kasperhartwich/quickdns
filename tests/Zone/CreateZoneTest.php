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


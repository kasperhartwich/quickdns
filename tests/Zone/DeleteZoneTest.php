<?php
namespace Tests\Zone;

use Tests\TestCase;
use QuickDns\QuickDns;

class DeleteZoneTest extends TestCase
{
    public function testDeleteSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $zone->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown domain');
        $quickDns->getZone('quickdns-api-test-domain.dk');
    }
}

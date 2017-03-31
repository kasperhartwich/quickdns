<?php
namespace Tests\Zone;

use Tests\TestCase;
use QuickDns\QuickDns;
use QuickDns\Zone;

class DeleteZoneTest extends TestCase
{
    public function testDeleteSuccess()
    {
        $quickDns = new QuickDns(self::API_EMAIL, self::API_PASSWORD);
        $zone = $quickDns->getZone('quickdns-api-test-domain.dk');
        $zone->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown domain');
        $quickDns->getZone('quickdns-api-test-domain.dk');
    }
}

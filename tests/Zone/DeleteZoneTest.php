<?php

namespace Tests\Zone;

use QuickDns\QuickDns;
use Tests\TestCase;

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

<?php
namespace Tests;

use QuickDns\QuickDns;

class QuickDnsTest extends TestCase
{
    public function testLoginSuccessfull()
    {
        $quickDns = new QuickDns(self::API_EMAIL, self::API_PASSWORD);
        $this->assertEquals(QuickDns::class, get_class($quickDns));
    }

    public function testLoginWrongPassword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Login failed.');
        $quickDns = new QuickDns(self::API_EMAIL, 'wrong-password');
    }

    public function testGetZones()
    {
        $quickDns = new QuickDns(self::API_EMAIL, self::API_PASSWORD);
        $zones = $quickDns->getZones();
        $this->assertTrue(is_Array($zones));

    }
}


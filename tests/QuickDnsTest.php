<?php
namespace Tests;

use QuickDns\QuickDns;

class QuickDnsTest extends TestCase
{
    public function testLoginSuccessfull()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $this->assertEquals(QuickDns::class, get_class($quickDns));
    }

    public function testLoginWrongPassword()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Login failed.');
        $quickDns = new QuickDns($this->apiEmail, 'wrong-password');
    }

    public function testGetZones()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $zones = $quickDns->getZones();
        $this->assertTrue(is_Array($zones));

    }
}


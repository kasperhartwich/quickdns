<?php
namespace Tests;

use QuickDns\QuickDns;

class QuickDnsTest extends TestCase
{
    public function testLoginSuccessfull()
    {
        $quickDns = new QuickDns(self::API_EMAIL, self::API_PASSWORD);
        $response = $quickDns->login();
        $this->assertTrue($response);
    }

    public function testLoginWrongPassword()
    {
        $quickDns = new QuickDns(self::API_EMAIL, 'wrong-password');
        $response = $quickDns->login();
        $this->assertFalse($response);
    }
}


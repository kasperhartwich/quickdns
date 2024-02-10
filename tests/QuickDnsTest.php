<?php

namespace Tests;

use QuickDns\QuickDns;

final class QuickDnsTest extends TestCase
{
    public function test_login_successfull()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $this->assertSame(QuickDns::class, get_class($quickDns));
    }

    public function test_login_wrong_password()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Login failed.');
        new QuickDns($this->apiEmail, 'wrong-password');
    }
}

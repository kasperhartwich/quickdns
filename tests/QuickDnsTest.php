<?php

namespace QuickDns\Tests;

use QuickDns\QuickDns;

final class QuickDnsTest extends TestCase
{
    public function test_login_wrong_password()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Login failed.');
        new QuickDns('wrong-email', 'wrong-password');
    }
}

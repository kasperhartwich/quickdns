<?php

declare(strict_types=1);

namespace QuickDns\Tests\Live;

use QuickDns\QuickDns;

final class QuickDnsTest extends TestCase
{
    public function test_login_wrong_password()
    {
        $this->expectException(\QuickDns\Exceptions\LoginFailed::class);
        $this->expectExceptionMessage('Login failed.');
        (new QuickDns('wrong-email', 'wrong-password'))->login();
    }
}

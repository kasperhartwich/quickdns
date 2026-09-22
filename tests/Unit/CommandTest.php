<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\UnrecognisedPage;

final class CommandTest extends TestCase
{
    public function test_ok_returns_the_response()
    {
        $xml = $this->quickDns(['addzone-ok'])->command('addzone', ['zone' => 'flyvende-agurk-pingvin.dk']);

        $this->assertSame('17286', $xml->filterXPath('//response/zoneid')->text());
    }

    public function test_error_throws_command_failed_with_statustext()
    {
        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Zonen eksisterer allerede');
        $this->quickDns(['addzone-exists'])->command('addzone', ['zone' => 'flyvende-agurk-pingvin.dk']);
    }

    public function test_html_page_throws_unrecognised_page()
    {
        // E.g. the login page after the session expired. 2.2 only looked for "ERROR", so it took
        // this for success.
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('Unexpected response to delzone');
        $this->quickDns(['login-failed'])->command('delzone', ['id' => 1]);
    }

    public function test_html_mentioning_error_throws_unrecognised_page()
    {
        $this->expectException(UnrecognisedPage::class);
        $this->quickDns(['<html><body>ERROR 500</body></html>'])->command('delzone', ['id' => 1]);
    }

    public function test_error_without_statustext()
    {
        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('QuickDNS answered ERROR');
        $this->quickDns(['<?xml version="1.0" encoding="ISO-8859-1"?><response><status>ERROR</status></response>'])->command('delzone', ['id' => 1]);
    }
}

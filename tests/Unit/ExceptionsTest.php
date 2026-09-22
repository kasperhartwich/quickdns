<?php

namespace QuickDns\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\NotFound;
use QuickDns\Exceptions\QuickDnsException;
use QuickDns\Exceptions\UnrecognisedPage;

final class ExceptionsTest extends TestCase
{
    /**
     * Callers written against 2.2 catch the SPL classes, so each new exception must still be one.
     */
    public function test_exceptions_keep_their_spl_parents()
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, new LoginFailed());
        $this->assertInstanceOf(\InvalidArgumentException::class, new CommandFailed());
        $this->assertInstanceOf(\UnexpectedValueException::class, new UnrecognisedPage());
        $this->assertInstanceOf(\UnexpectedValueException::class, new NotFound());
    }

    public function test_exceptions_share_one_interface()
    {
        foreach ([LoginFailed::class, CommandFailed::class, UnrecognisedPage::class, NotFound::class] as $class) {
            $this->assertInstanceOf(QuickDnsException::class, new $class());
        }
    }

    public function test_wrong_login_throws_login_failed()
    {
        $this->expectException(LoginFailed::class);
        $this->expectExceptionMessage('Login failed.');
        $this->quickDnsWithLogin('login-failed');
    }

    public function test_unknown_login_page_throws_unrecognised_page()
    {
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('Unknown response at login');
        $this->quickDnsWithLogin('<html><body>Vedligeholdelse</body></html>');
    }

    public function test_unknown_zone_throws_not_found()
    {
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Unknown domain');
        $this->quickDns(['zones'])->getZone('findes-ikke.dk');
    }

    public function test_unknown_template_throws_not_found()
    {
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Unknown template');
        $this->quickDns(['templates'])->getTemplate('findes-ikke');
    }

    public function test_unknown_group_throws_not_found()
    {
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Unknown group');
        $this->quickDns(['groups'])->getGroup('findes-ikke');
    }

    #[DataProvider('listMethods')]
    public function test_list_on_a_logged_out_page_throws_unrecognised_page(string $method, string $page)
    {
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('Unexpected page at '.$page.': not logged in');
        $this->quickDns(['login-failed'])->$method();
    }

    public static function listMethods(): array
    {
        return [['getZones', 'zones'], ['getTemplates', 'templates'], ['getGroups', 'groups']];
    }
}

<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\InvalidRecord;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\MissingId;
use QuickDns\Exceptions\NotFound;
use QuickDns\Exceptions\QuickDnsException;
use QuickDns\Exceptions\RecordLocked;
use QuickDns\Exceptions\RecordRejected;
use QuickDns\Exceptions\StaleRecord;
use QuickDns\Exceptions\UnrecognisedPage;

final class ExceptionsTest extends TestCase
{
    public static function exceptions(): array
    {
        return [
            [LoginFailed::class], [CommandFailed::class], [UnrecognisedPage::class], [NotFound::class],
            [MissingId::class], [InvalidRecord::class], [RecordLocked::class], [StaleRecord::class],
            [RecordRejected::class],
        ];
    }

    #[DataProvider('exceptions')]
    public function test_every_exception_extends_the_one_parent_and_no_spl_class(string $class)
    {
        $exception = $class === RecordRejected::class ? new RecordRejected('no') : new $class();

        $this->assertInstanceOf(QuickDnsException::class, $exception);
        $this->assertNotInstanceOf(\RuntimeException::class, $exception);
        $this->assertNotInstanceOf(\LogicException::class, $exception);
    }

    public function test_the_parent_cannot_be_thrown_on_its_own()
    {
        $this->assertTrue((new \ReflectionClass(QuickDnsException::class))->isAbstract());
    }

    public function test_command_failed_carries_the_answer()
    {
        $quickDns = $this->quickDns(['<response><status>ERROR</status><statustext>Zonen eksisterer allerede</statustext><zoneid>42</zoneid></response>']);

        try {
            $quickDns->command('addzone', ['zone' => 'example.dk']);
            $this->fail('No exception');
        } catch (CommandFailed $e) {
            $this->assertSame('Zonen eksisterer allerede', $e->getMessage());
            $this->assertSame('Zonen eksisterer allerede', $e->statusText());
            $this->assertSame('addzone', $e->function());
            $this->assertSame('ERROR', $e->status());
            $this->assertSame(['zoneid' => '42'], $e->fields());
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
        $this->quickDns(['login-failed', 'login-ok', 'login-failed'])->$method();
    }

    #[DataProvider('listTables')]
    public function test_list_page_without_its_table_throws_unrecognised_page(string $method, string $message)
    {
        // A logged-in page, just not the right one.
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage($message);
        $this->quickDns(['groups'])->$method();
    }

    public static function listTables(): array
    {
        return [['getZones', 'No zone_table on the zones page'], ['getTemplates', 'No zone_table on the templates page']];
    }

    public function test_groups_page_without_its_table_throws_unrecognised_page()
    {
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('No group_table on the groups page');
        $this->quickDns(['zones'])->getGroups();
    }

    public static function listMethods(): array
    {
        return [['getZones', 'zones'], ['getTemplates', 'templates'], ['getGroups', 'groups']];
    }
}

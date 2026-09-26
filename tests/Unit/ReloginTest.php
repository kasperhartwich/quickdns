<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\RecordSet;
use QuickDns\Zone;

/**
 * QuickDNS ends a session without a word and then answers with its login page. The recorded login
 * page (login-failed) is what a logged-out request gets.
 */
final class ReloginTest extends TestCase
{
    private function paths(): array
    {
        return array_map(fn ($entry) => $entry['request']->getUri()->getPath(), $this->history);
    }

    public function test_a_logged_out_answer_logs_in_again_and_resends_once()
    {
        $zones = $this->quickDns(['login-failed', 'login-ok', 'zones'])->getZones();

        $this->assertCount(1, $zones);
        $this->assertSame(['/login', '/zones', '/login', '/zones'], $this->paths());
    }

    public function test_a_command_is_resent_with_its_parameters()
    {
        $this->quickDns(['login-failed', 'login-ok', 'delzone'])->command('delzone', ['id' => 17287]);

        $this->assertSame(['/login', '/delzone', '/login', '/delzone'], $this->paths());
        $this->assertSame('id=17287', $this->history[3]['request']->getUri()->getQuery());
    }

    public function test_it_retries_only_once()
    {
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('Unexpected page at zones: not logged in');

        try {
            $this->quickDns(['login-failed', 'login-ok', 'login-failed'])->getZones();
        } finally {
            $this->assertSame(['/login', '/zones', '/login', '/zones'], $this->paths());
        }
    }

    public function test_credentials_that_stopped_working_throw_login_failed()
    {
        $this->expectException(LoginFailed::class);
        $this->quickDns(['login-failed', 'login-failed'])->getZones();
    }

    public function test_the_login_request_itself_is_never_resent()
    {
        $quickDns = $this->quickDns(['login-failed']);

        $quickDns->request('https://www.quickdns.dk/login', ['email' => 'x', 'password' => 'y'], 'POST');

        $this->assertSame(['/login', '/login'], $this->paths());
    }

    public function test_a_page_that_is_not_the_login_page_is_not_resent()
    {
        try {
            $this->quickDns(['<html><body>Vedligeholdelse</body></html>'])->getZones();
            $this->fail('No exception');
        } catch (UnrecognisedPage) {
            $this->assertSame(['/login', '/zones'], $this->paths());
        }
    }

    public function test_a_logout_in_the_middle_of_an_edit_is_not_papered_over()
    {
        $zone = new Zone($this->quickDns(['editzone', 'login-failed', 'login-failed']), 'flyvende-agurk-pingvin.dk', 17363);

        try {
            $zone->edit(fn (RecordSet $records) => $records->add('www', 'A', '192.0.2.10'));
            $this->fail('No exception');
        } catch (UnrecognisedPage $e) {
            $this->assertStringContainsString('logged the client out in the middle of an edit', $e->getMessage());
            // No second login: the pending table went with the old session.
            $this->assertSame(['/login', '/editzone', '/submitzonechange', '/editzonedone'], $this->paths());
        }
    }
}

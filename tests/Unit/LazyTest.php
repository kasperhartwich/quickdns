<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\QuickDns;

/**
 * The client logs in on its first request, not when it is built.
 */
final class LazyTest extends TestCase
{
    private function client(array $fixtures): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_map(fn ($fixture) => $this->response($fixture), $fixtures)));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private function uris(): array
    {
        return array_map(fn ($entry) => (string) $entry['request']->getUri(), $this->history);
    }

    public function test_sends_nothing_until_used()
    {
        $quickDns = new QuickDns('test@example.dk', 'secret', $this->client([]));

        $this->assertSame([], $this->history);
        $this->assertFalse($quickDns->isLoggedIn());
    }

    public function test_logs_in_once_before_the_first_request()
    {
        $quickDns = new QuickDns('test@example.dk', 'secret', $this->client(['login-ok', 'zones', 'templates']));

        $quickDns->getZones();
        $quickDns->getTemplates();

        $this->assertSame(['https://www.quickdns.dk/login', 'https://www.quickdns.dk/zones', 'https://www.quickdns.dk/templates'], $this->uris());
        $this->assertTrue($quickDns->isLoggedIn());
    }

    public function test_wrong_login_throws_on_first_request()
    {
        $quickDns = new QuickDns('test@example.dk', 'wrong', $this->client(['login-failed']));

        $this->expectException(LoginFailed::class);
        $quickDns->getZones();
    }

    public function test_login_checks_the_credentials_up_front()
    {
        $quickDns = new QuickDns('test@example.dk', 'secret', $this->client(['login-ok', 'zones']));

        $quickDns->login();
        $quickDns->getZones();

        $this->assertSame(['https://www.quickdns.dk/login', 'https://www.quickdns.dk/zones'], $this->uris());
    }

    public function test_a_failed_login_leaves_the_client_logged_out()
    {
        $quickDns = new QuickDns('test@example.dk', 'wrong', $this->client(['login-failed']));

        try {
            $quickDns->login();
            $this->fail('No exception');
        } catch (LoginFailed) {
            $this->assertFalse($quickDns->isLoggedIn());
        }
    }

    /**
     * @group legacy
     */
    public function test_lazy_is_the_constructor()
    {
        $quickDns = QuickDns::lazy('test@example.dk', 'secret', $this->client([]));

        $this->assertInstanceOf(QuickDns::class, $quickDns);
        $this->assertSame([], $this->history);
    }

    public function test_lazy_returns_the_subclass_and_runs_its_constructor()
    {
        $class = get_class(new class('a', 'b') extends QuickDns
        {
            public string $initialised = 'no';

            public function __construct(string $email, string $password, ?\GuzzleHttp\ClientInterface $client = null)
            {
                parent::__construct($email, $password, $client);
                $this->initialised = 'yes';
            }
        });

        $this->assertSame('yes', $class::lazy('a', 'b')->initialised);
    }

    /**
     * A subclass' login() may not throw and not say it logged in, as 2.x ones returned true
     * instead. It is still called once, not before every request.
     */
    public function test_login_override_without_parent()
    {
        $quickDns = new class('a', 'b', $this->client(['zones', 'zones'])) extends QuickDns
        {
            public int $logins = 0;

            public function login(): void
            {
                $this->logins++;
            }
        };

        $quickDns->getZones();
        $quickDns->getZones();

        $this->assertSame(1, $quickDns->logins);
    }

    /**
     * A login() override that posts to "/login" must not recurse through the automatic login.
     */
    public function test_login_override_posting_to_slash_login()
    {
        $quickDns = new class('a', 'b', $this->client(['login-ok', 'zones'])) extends QuickDns
        {
            public function login(): void
            {
                $this->request('/login', ['email' => 'a', 'password' => 'b'], self::METHOD_POST);
            }
        };

        $quickDns->getZones();

        $this->assertSame(['https://www.quickdns.dk/login', 'https://www.quickdns.dk/zones'], $this->uris());
    }
}

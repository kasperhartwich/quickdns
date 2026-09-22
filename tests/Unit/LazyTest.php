<?php

namespace QuickDns\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\QuickDns;

final class LazyTest extends TestCase
{
    private function lazy(array $fixtures): QuickDns
    {
        $stack = HandlerStack::create(new MockHandler(array_map(fn ($fixture) => $this->response($fixture), $fixtures)));
        $stack->push(Middleware::history($this->history));

        return QuickDns::lazy('test@example.dk', 'secret', new Client(['handler' => $stack]));
    }

    public function test_sends_nothing_until_used()
    {
        $quickDns = $this->lazy([]);

        $this->assertInstanceOf(QuickDns::class, $quickDns);
        $this->assertSame([], $this->history);
    }

    public function test_logs_in_once_before_the_first_request()
    {
        $quickDns = $this->lazy(['login-ok', 'zones', 'templates']);

        $quickDns->getZones();
        $quickDns->getTemplates();

        $uris = array_map(fn ($entry) => (string) $entry['request']->getUri(), $this->history);
        $this->assertSame(['https://www.quickdns.dk/login', 'https://www.quickdns.dk/zones', 'https://www.quickdns.dk/templates'], $uris);
    }

    public function test_wrong_login_throws_on_first_request()
    {
        $quickDns = $this->lazy(['login-failed']);

        $this->expectException(LoginFailed::class);
        $this->expectExceptionMessage('Login failed.');
        $quickDns->getZones();
    }

    public function test_returns_the_subclass()
    {
        $class = get_class(new class('a', 'b', new Client(['handler' => HandlerStack::create(new MockHandler([$this->response('login-ok')]))])) extends QuickDns
        {
        });

        $this->assertInstanceOf($class, $class::lazy('a', 'b'));
    }

    public function test_runs_the_subclass_constructor()
    {
        $class = get_class(new class('a', 'b', new Client(['handler' => HandlerStack::create(new MockHandler([$this->response('login-ok')]))])) extends QuickDns
        {
            public string $initialised;

            public function __construct($email, $password, $client = null)
            {
                $this->initialised = 'yes';
                parent::__construct($email, $password, $client);
            }
        });

        $this->assertSame('yes', $class::lazy('a', 'b')->initialised);
        $this->assertInstanceOf(QuickDns::class, $this->lazy([]), 'The next lazy() is lazy too.');
        $this->assertSame([], $this->history);
    }

    public function test_quickdns_built_inside_a_subclass_constructor_is_not_lazy()
    {
        $innerHistory = [];
        $inner = HandlerStack::create(new MockHandler([$this->response('login-ok'), $this->response('login-ok')]));
        $inner->push(Middleware::history($innerHistory));
        $class = get_class(new class('a', 'b', new Client(['handler' => HandlerStack::create(new MockHandler([$this->response('login-ok')]))])) extends QuickDns
        {
            public static $innerClient;

            public $inner;

            public function __construct($email, $password, $client = null)
            {
                if (self::$innerClient) {
                    $this->inner = new QuickDns($email, $password, self::$innerClient);
                }
                parent::__construct($email, $password, $client);
            }
        });
        $class::$innerClient = new Client(['handler' => $inner]);
        $outer = HandlerStack::create(new MockHandler([]));
        $outer->push(Middleware::history($this->history));

        $quickDns = $class::lazy('a', 'b', new Client(['handler' => $outer]));

        $this->assertCount(1, $innerHistory, 'The inner QuickDns logs in right away.');
        $this->assertSame([], $this->history, 'The lazy outer one sends nothing.');
        $this->assertInstanceOf(QuickDns::class, $quickDns->inner);
    }

    public function test_lazy_inside_a_subclass_constructor_keeps_the_outer_call_lazy()
    {
        $class = get_class(new class('a', 'b', new Client(['handler' => HandlerStack::create(new MockHandler([$this->response('login-ok')]))])) extends QuickDns
        {
            public static $buildInner = false;

            public $inner;

            public function __construct($email, $password, $client = null)
            {
                if (self::$buildInner) {
                    $this->inner = QuickDns::lazy($email, $password);
                }
                parent::__construct($email, $password, $client);
            }
        });
        $class::$buildInner = true;
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($this->history));

        $quickDns = $class::lazy('a', 'b', new Client(['handler' => $stack]));

        $this->assertSame([], $this->history);
        $this->assertInstanceOf(QuickDns::class, $quickDns->inner);
    }

    public function test_constructor_after_lazy_logs_in_again()
    {
        QuickDns::lazy('a', 'b');

        $this->quickDns();

        $this->assertCount(1, $this->history, 'new QuickDns() after lazy() must still log in right away.');
    }

    /**
     * A 2.2 subclass may override login() without calling parent::login().
     */
    public function test_login_override_without_parent()
    {
        $quickDns = new class('a', 'b') extends QuickDns
        {
            public int $logins = 0;

            public function login()
            {
                $this->logins++;

                return true;
            }

            public function request($function, $options = [], $method = self::METHOD_GET): string
            {
                return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', file_get_contents(__DIR__.'/../Fixtures/zones.html'));
            }
        };

        $quickDns->getZones();
        $quickDns->getZones();

        $this->assertSame(1, $quickDns->logins);
    }

    /**
     * A login() override that posts to "/login" must not recurse through the lazy login.
     */
    public function test_lazy_login_override_posting_to_slash_login()
    {
        $stack = HandlerStack::create(new MockHandler([$this->response('login-ok'), $this->response('zones')]));
        $stack->push(Middleware::history($this->history));
        $class = get_class(new class('a', 'b', new Client(['handler' => HandlerStack::create(new MockHandler([$this->response('login-ok')]))])) extends QuickDns
        {
            public function login()
            {
                return str_contains($this->request('/login', ['email' => 'a', 'password' => 'b'], self::METHOD_POST), 'Log ud');
            }
        });

        $class::lazy('a', 'b', new Client(['handler' => $stack]))->getZones();

        $this->assertCount(2, $this->history);
    }
}

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
}

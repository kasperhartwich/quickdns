<?php

namespace QuickDns\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use QuickDns\QuickDns;

class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Requests sent by the mocked client, oldest first.
     *
     * @var array
     */
    protected $history = [];

    /**
     * QuickDns logged in against a mocked client that answers with the given fixtures.
     * The login response is queued first.
     *
     * @param  string[]  $fixtures  Fixture names (without .html) or raw bodies, in request order
     */
    protected function quickDns(array $fixtures = []): QuickDns
    {
        return $this->quickDnsWithLogin('login-ok', $fixtures);
    }

    /**
     * Like quickDns(), with the given fixture as the answer to the login request.
     */
    protected function quickDnsWithLogin(string $login, array $fixtures = []): QuickDns
    {
        $responses = array_map(fn ($fixture) => $this->response($fixture), array_merge([$login], $fixtures));
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new QuickDns('test@example.dk', 'secret', new Client(['handler' => $stack]));
    }

    protected function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$name.'.html');
    }

    protected function response(string $fixture): Response
    {
        $body = is_file(__DIR__.'/../Fixtures/'.$fixture.'.html') ? $this->fixture($fixture) : $fixture;

        return new Response(200, ['Content-Type' => 'text/html'], $body);
    }

    /**
     * The last request's URI relative to https://www.quickdns.dk/, e.g. "delzone?id=1".
     */
    protected function lastRequestUri(): string
    {
        $uri = (string) end($this->history)['request']->getUri();
        $this->assertStringStartsWith('https://www.quickdns.dk/', $uri);

        return substr($uri, strlen('https://www.quickdns.dk/'));
    }
}

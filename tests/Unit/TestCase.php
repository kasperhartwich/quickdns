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
        $responses = array_map(fn ($fixture) => $this->response($fixture), array_merge(['login-ok'], $fixtures));
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        // QuickDns builds its own client and logs in from the constructor, so swap the client in
        // before logging in.
        $quickDns = (new \ReflectionClass(QuickDns::class))->newInstanceWithoutConstructor();
        foreach (['email' => 'test@example.dk', 'password' => 'secret', 'client' => new Client(['handler' => $stack])] as $property => $value) {
            (new \ReflectionProperty(QuickDns::class, $property))->setValue($quickDns, $value);
        }
        if (! $quickDns->login()) {
            throw new \RuntimeException('Mocked login failed.');
        }

        return $quickDns;
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

    protected function lastRequestUri(): string
    {
        return (string) end($this->history)['request']->getUri();
    }
}

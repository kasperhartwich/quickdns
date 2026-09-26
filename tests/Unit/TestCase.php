<?php

declare(strict_types=1);

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

    /**
     * A recorded page or command answer. Command answers are .xml, pages are .html.
     */
    protected function fixture(string $name): string
    {
        return file_get_contents($this->fixturePath($name) ?? throw new \InvalidArgumentException('No fixture '.$name));
    }

    /**
     * The recorded zones page, its one zone (17287) using these templates and in these groups.
     *
     * @param  int[]  $templateIds
     * @param  int[]  $groupIds
     */
    protected function zonesPage(array $templateIds, array $groupIds = [738]): string
    {
        $array = fn (array $ids) => 'new Array('.implode(',', array_map(fn (int $id) => "'".$id."'", $ids)).')';

        return str_replace(
            ["templates(parentNode.parentNode.rowIndex, new Array('17284'))", "groups(parentNode.parentNode.rowIndex, new Array(), new Array('738'))"],
            ['templates(parentNode.parentNode.rowIndex, '.$array($templateIds).')', 'groups(parentNode.parentNode.rowIndex, new Array(), '.$array($groupIds).')'],
            $this->fixture('zones'),
        );
    }

    protected function response(string $fixture): Response
    {
        $path = $this->fixturePath($fixture);
        $type = $path !== null && str_ends_with($path, '.xml') ? 'text/xml' : 'text/html';

        return new Response(200, ['Content-Type' => $type], $path !== null ? file_get_contents($path) : $fixture);
    }

    private function fixturePath(string $name): ?string
    {
        foreach (['.html', '.xml'] as $extension) {
            if (is_file($path = __DIR__.'/../Fixtures/'.$name.$extension)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The last request's URI relative to https://www.quickdns.dk/, e.g. "delzone?id=1".
     */
    protected function lastRequestUri(): string
    {
        return $this->requestUri(count($this->history) - 1);
    }

    /**
     * The nth request's URI relative to https://www.quickdns.dk/ (0 is the login).
     */
    protected function requestUri(int $n): string
    {
        $uri = (string) $this->history[$n]['request']->getUri();
        $this->assertStringStartsWith('https://www.quickdns.dk/', $uri);

        return substr($uri, strlen('https://www.quickdns.dk/'));
    }
}

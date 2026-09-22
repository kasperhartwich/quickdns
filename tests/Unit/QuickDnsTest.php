<?php

namespace QuickDns\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use QuickDns\QuickDns;

final class QuickDnsTest extends TestCase
{
    public function test_login_posts_credentials()
    {
        $this->quickDns();

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://www.quickdns.dk/login', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame(['email' => 'test@example.dk', 'password' => 'secret'], $form);
    }

    public function test_session_cookie_is_kept_with_an_injected_client()
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Set-Cookie' => 'PHPSESSID=abc; path=/'], $this->fixture('login-ok')),
            $this->response('zones'),
        ]));
        $stack->push(Middleware::history($this->history));

        (new QuickDns('test@example.dk', 'secret', new Client(['handler' => $stack])))->getZones();

        $this->assertSame('PHPSESSID=abc', $this->history[1]['request']->getHeaderLine('Cookie'));
    }

    public function test_login_failed()
    {
        $quickDns = $this->quickDns(['login-failed']);

        $this->assertFalse($quickDns->login());
    }

    public function test_login_unknown_response()
    {
        $quickDns = $this->quickDns(['<html><body>Vedligeholdelse</body></html>']);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown response at login');
        $quickDns->login();
    }

    public function test_get_zones()
    {
        $zones = $this->quickDns(['zones'])->getZones();

        $this->assertSame('zones', $this->lastRequestUri());
        $this->assertCount(1, $zones);
        $this->assertSame('17287', $zones[0]->id);
        $this->assertSame('flyvende-agurk-pingvin.dk', $zones[0]->domain);
        $this->assertSame(['test-template'], $zones[0]->templates);
        $this->assertSame(['test-group'], $zones[0]->groups);
        $this->assertSame('2026-09-22 18:21:28', $zones[0]->updated);
    }

    /**
     * Issue #8: QuickDNS used to show 50 zones per page. It now lists them all on one page
     * (recorded with 51 zones on 2026-09-22).
     */
    public function test_get_zones_reads_more_than_50()
    {
        $zones = $this->quickDns(['zones-51'])->getZones();

        $this->assertCount(51, $zones);
        $this->assertSame('flyvende-agurk-pingvin-51.dk', end($zones)->domain);
    }

    public function test_get_zones_empty()
    {
        $this->assertSame([], $this->quickDns(['zones-empty'])->getZones());
    }

    public function test_get_templates()
    {
        $templates = $this->quickDns(['templates'])->getTemplates();

        $this->assertSame('templates', $this->lastRequestUri());
        $this->assertCount(1, $templates);
        $this->assertSame(17284, $templates[0]->id);
        $this->assertSame('test-template', $templates[0]->name);
        $this->assertSame(1, $templates[0]->zones);
        $this->assertSame([], $templates[0]->groups);
    }

    public function test_get_groups()
    {
        $groups = array_values($this->quickDns(['groups'])->getGroups());

        $this->assertSame('groups', $this->lastRequestUri());
        $this->assertCount(1, $groups);
        $this->assertSame(738, $groups[0]->id);
        $this->assertSame('test-group', $groups[0]->name);
        $this->assertSame([], $groups[0]->members);
    }

    public function test_get_group_by_name()
    {
        $this->assertSame(738, $this->quickDns(['groups'])->getGroup('test-group')->id);
    }

    public function test_request_resolves_paths_like_base_uri()
    {
        $quickDns = $this->quickDns(['zones', 'zones', 'zones']);

        $quickDns->request('zones');
        $this->assertSame('zones', $this->lastRequestUri());
        $quickDns->request('/zones');
        $this->assertSame('zones', $this->lastRequestUri());
        $quickDns->request('https://www.quickdns.dk/zones');
        $this->assertSame('zones', $this->lastRequestUri());
    }
}

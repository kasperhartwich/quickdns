<?php

namespace QuickDns\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\NotFound;
use QuickDns\Group;
use QuickDns\QuickDns;
use QuickDns\Template;
use QuickDns\Testing\FakeQuickDns;
use QuickDns\Zone;

/**
 * The fake must behave like quickdns.dk through the public API, so these mirror the live tests.
 */
final class FakeQuickDnsTest extends TestCase
{
    public function test_logs_in_with_the_right_credentials_only()
    {
        $fake = new FakeQuickDns('me@example.dk', 'pw');

        $this->assertInstanceOf(QuickDns::class, new QuickDns('me@example.dk', 'pw', $fake->client()));

        $this->expectException(LoginFailed::class);
        new QuickDns('me@example.dk', 'wrong', $fake->client());
    }

    public function test_refuses_requests_without_a_session()
    {
        $fake = new FakeQuickDns();
        $body = (string) $fake->client()->request('GET', 'https://www.quickdns.dk/zones')->getBody();

        $this->assertStringNotContainsString('Log ud', $body);
    }

    public function test_zones_lifecycle()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('standard');
        $fake->addGroup('kunder');
        $quickDns = $fake->quickDns();

        $zone = (new Zone($quickDns, 'flyvende-agurk-pingvin.dk'))->create();
        $this->assertTrue($fake->hasZone('flyvende-agurk-pingvin.dk'));
        $this->assertNotNull($zone->id);

        $quickDns->getTemplate('standard')->addZone($zone);
        $quickDns->getGroup('kunder')->addZone($zone);
        $this->assertSame(['standard'], $fake->templatesOf('flyvende-agurk-pingvin.dk'));
        $this->assertSame(['standard'], $quickDns->getZone('flyvende-agurk-pingvin.dk')->templates);
        $this->assertSame(['kunder'], $quickDns->getZone('flyvende-agurk-pingvin.dk')->groups);
        $this->assertSame(1, $quickDns->getTemplate('standard')->zones);

        $quickDns->getTemplate('standard')->removeZone($zone);
        $quickDns->getGroup('kunder')->removeZone($zone);
        $this->assertSame([], $quickDns->getZone('flyvende-agurk-pingvin.dk')->templates);
        $this->assertSame([], $fake->groupsOf('flyvende-agurk-pingvin.dk'));

        $zone->delete();
        $this->assertFalse($fake->hasZone('flyvende-agurk-pingvin.dk'));
        $this->assertSame([], $quickDns->getZones());
    }

    public function test_command_errors_match_quickdns()
    {
        $fake = new FakeQuickDns();
        $quickDns = $fake->quickDns();
        (new Zone($quickDns, 'flyvende-agurk-pingvin.dk'))->create();

        foreach ([
            'Zonen eksisterer allerede' => fn () => (new Zone($quickDns, 'flyvende-agurk-pingvin.dk'))->create(),
            'Zonens navn er ugyldigt' => fn () => (new Zone($quickDns, 'flyvende-agurk-pingvin.invalid'))->create(),
            'Skabelonens navn er ugyldigt' => fn () => (new Template($quickDns, '@@'))->create(),
            'Gruppens navn er ugyldigt' => fn () => (new Group($quickDns, '@@'))->create(),
        ] as $message => $call) {
            try {
                $call();
                $this->fail('Expected CommandFailed: '.$message);
            } catch (CommandFailed $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_templates_and_groups()
    {
        $fake = new FakeQuickDns();
        $quickDns = $fake->quickDns();

        $template = (new Template($quickDns, 'sjaskende-rabarber'))->create();
        (new Group($quickDns, 'sjaskende-rabarber'))->create();
        $this->assertTrue($fake->hasTemplate('sjaskende-rabarber'));
        $this->assertSame($template->id, $quickDns->getTemplate('sjaskende-rabarber')->id);

        $quickDns->getGroup('sjaskende-rabarber')->delete();
        $template->delete();
        $this->assertFalse($fake->hasGroup('sjaskende-rabarber'));
        $this->assertSame([], $quickDns->getTemplates());

        $this->expectException(NotFound::class);
        $quickDns->getGroup('sjaskende-rabarber');
    }

    public function test_records()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $fake->addRecord('flyvende-agurk-pingvin.dk', '@', 'MX', 'mx1.example.dk.', 3600, 10);
        $fake->addRecord('flyvende-agurk-pingvin.dk', 'www', 'CNAME', '@', 300);

        $records = $fake->quickDns()->getZone('flyvende-agurk-pingvin.dk')->getRecords();

        $this->assertCount(6, $records);
        $this->assertSame(['@', null, 'NS', 'QuickDNS global'], [$records[0]->name, $records[0]->ttl, $records[0]->type, $records[0]->template]);
        $this->assertSame(['MX', 10, 'mx1.example.dk.', 5, null], [$records[4]->type, $records[4]->priority, $records[4]->value, $records[4]->row, $records[4]->template]);
        $this->assertSame(['www', 300, '@'], [$records[5]->name, $records[5]->ttl, $records[5]->value]);
    }

    public function test_danish_letters_survive()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('rødgrød-med-fløde');

        $this->assertSame('rødgrød-med-fløde', $fake->quickDns()->getTemplates()[0]->name);
    }

    public function test_lazy_login_and_request_log()
    {
        $fake = new FakeQuickDns();
        $quickDns = QuickDns::lazy('test@example.dk', 'secret', new Client(['handler' => HandlerStack::create($fake)]));
        $this->assertSame([], $fake->requests());

        $quickDns->getZones();

        $this->assertSame(['/login', '/zones'], array_map(fn ($r) => $r->getUri()->getPath(), $fake->requests()));
    }
}

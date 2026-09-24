<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\NotFound;
use QuickDns\Exceptions\RecordLocked;
use QuickDns\Exceptions\RecordRejected;
use QuickDns\Group;
use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
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

    public function test_deleting_a_template_in_use_is_a_server_error()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('standard');
        $fake->addZone('flyvende-agurk-pingvin.dk', ['standard']);

        try {
            $fake->quickDns()->getTemplate('standard')->delete();
            $this->fail('Expected a server error.');
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $this->assertSame(500, $e->getResponse()->getStatusCode());
        }
        $this->assertTrue($fake->hasTemplate('standard'));
    }

    public function test_deleting_a_group_in_use_takes_it_off_its_zones()
    {
        $fake = new FakeQuickDns();
        $fake->addGroup('kunder');
        $fake->addZone('flyvende-agurk-pingvin.dk', [], ['kunder']);
        $quickDns = $fake->quickDns();

        $quickDns->getGroup('kunder')->delete();

        $this->assertSame([], $quickDns->getZone('flyvende-agurk-pingvin.dk')->groups);
    }

    public function test_names_outside_latin_1_are_invalid()
    {
        $quickDns = (new FakeQuickDns())->quickDns();

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Skabelonens navn er ugyldigt');
        (new Template($quickDns, '東京'))->create();
    }

    public function test_created_answers_carry_the_user_like_quickdns()
    {
        $xml = (new FakeQuickDns())->quickDns()->command('addzone', ['zone' => 'flyvende-agurk-pingvin.dk', 'getdata' => 0]);

        $this->assertSame((string) FakeQuickDns::USER, $xml->filterXPath('//response/user')->text());
    }

    public function test_writing_records_through_an_edit_session()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $fake->addRecord('flyvende-agurk-pingvin.dk', 'old', 'A', '192.0.2.1');
        $zone = $fake->quickDns()->getZone('flyvende-agurk-pingvin.dk');

        $zone->edit(function (RecordSet $records) {
            $records->add('www', 'A', '192.0.2.10', ttl: 3600);
            $records->add('@', RecordType::MX, 'mx1.example.dk.', ttl: 3600, priority: 10);
            $records->remove($records->sole(name: 'old'));
        });

        $summary = array_map(fn (Record $r) => "{$r->name} {$r->type} {$r->value}", $zone->getRecords());
        $this->assertSame([
            '@ NS ns1.quickdns.dk.',
            '@ NS ns2.quickdns.dk.',
            '@ NS ns3.quickdns.dk.',
            '@ NS ns4.quickdns.dk.',
            '@ MX mx1.example.dk.',
            'www A 192.0.2.10',
        ], $summary);
    }

    public function test_a_discarded_edit_changes_nothing()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $zone = $fake->quickDns()->getZone('flyvende-agurk-pingvin.dk');

        try {
            $zone->edit(function (RecordSet $records) {
                $records->add('www', 'A', '192.0.2.10');
                throw new \RuntimeException('no');
            });
        } catch (\RuntimeException) {
        }

        $this->assertCount(4, $fake->recordsOf('flyvende-agurk-pingvin.dk'));
        $this->assertFalse($fake->hasPendingChanges('flyvende-agurk-pingvin.dk'));
    }

    public function test_a_rejected_record_saves_nothing_at_all()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $zone = $fake->quickDns()->getZone('flyvende-agurk-pingvin.dk');
        $fake->failNextChange('Noget gik galt.');

        try {
            $zone->edit(function (RecordSet $records) {
                $records->add('www', 'A', '192.0.2.10');
                $records->add('mail', 'A', '192.0.2.11');
            });
            $this->fail('Expected RecordRejected.');
        } catch (RecordRejected $e) {
            $this->assertSame('Noget gik galt.', $e->getMessage());
        }

        $this->assertCount(4, $fake->recordsOf('flyvende-agurk-pingvin.dk'), 'Not even the accepted record is saved.');
    }

    public function test_template_records_are_locked_in_the_fake_too()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $zone = $fake->quickDns()->getZone('flyvende-agurk-pingvin.dk');

        $this->expectException(RecordLocked::class);
        $zone->edit(fn (RecordSet $records) => $records->remove($records->all()[0]));
    }

    public function test_one_shot_helpers()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $zone = $fake->quickDns()->getZone('flyvende-agurk-pingvin.dk');

        $added = $zone->addRecord('www', 'A', '192.0.2.10', ttl: 3600);
        $this->assertSame('www', $added->name);

        $zone->replaceRecord($added, $added->withValue('192.0.2.11'));
        $this->assertSame('192.0.2.11', $zone->getRecords()[4]->value);

        $zone->deleteRecord($zone->getRecords()[4]);
        $this->assertCount(4, $zone->getRecords());
    }

    public function test_saving_with_the_wrong_sequence_number_saves_nothing()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $client = new Client(['handler' => HandlerStack::create($fake), 'cookies' => true]);
        $client->request('POST', 'https://www.quickdns.dk/login', ['form_params' => ['email' => 'test@example.dk', 'password' => 'secret']]);
        $page = (string) $client->request('GET', 'https://www.quickdns.dk/editzone', ['query' => ['id' => 1000]])->getBody();
        preg_match("/init\('([0-9a-f]+)'/", $page, $match);

        $client->request('GET', 'https://www.quickdns.dk/submitzonechange', ['query' => ['action' => 'initial', 'seq' => 0, 'zkey' => $match[1]]]);
        $client->request('GET', 'https://www.quickdns.dk/submitzonechange', ['query' => ['action' => 'edit', 'seq' => 1, 'zkey' => $match[1], 'row' => -1, 'record' => 'www', 'ttl' => 3600, 'type' => 'A', 'priority' => '', 'value' => '192.0.2.10']]);
        // The next sequence number instead of the last one: the live service saves nothing.
        $client->request('GET', 'https://www.quickdns.dk/editzonedone', ['query' => ['save' => 1, 'seq' => 2, 'zkey' => $match[1]]]);

        $this->assertCount(4, $fake->recordsOf('flyvende-agurk-pingvin.dk'));
    }

    public function test_the_fake_rejects_what_quickdns_rejects()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('flyvende-agurk-pingvin.dk');
        $client = new Client(['handler' => HandlerStack::create($fake), 'cookies' => true]);
        $client->request('POST', 'https://www.quickdns.dk/login', ['form_params' => ['email' => 'test@example.dk', 'password' => 'secret']]);
        $page = (string) $client->request('GET', 'https://www.quickdns.dk/editzone', ['query' => ['id' => 1000]])->getBody();
        preg_match("/init\('([0-9a-f]+)'/", $page, $match);

        // Straight at the endpoint, so the library's own validation cannot get in the way.
        $answer = (string) $client->request('GET', 'https://www.quickdns.dk/submitzonechange', ['query' => [
            'action' => 'edit', 'seq' => 1, 'zkey' => $match[1], 'row' => -1,
            'record' => 'bad', 'ttl' => 3600, 'type' => 'TXT', 'priority' => '', 'value' => 'say "hi"',
        ]])->getBody();

        $this->assertStringContainsString('indeholder ugyldige tegn', mb_convert_encoding($answer, 'UTF-8', 'ISO-8859-1'));
        $this->assertStringContainsString('<badrecord>', $answer);
    }
}

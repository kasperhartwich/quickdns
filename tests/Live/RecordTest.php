<?php

namespace QuickDns\Tests\Live;

use QuickDns\Exceptions\InvalidRecord;
use QuickDns\Exceptions\RecordLocked;
use QuickDns\Exceptions\RecordRejected;
use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
use QuickDns\Zone;

/**
 * Writing records against the real service. The zone is its own, so these tests do not collide
 * with ZoneTest, and it is deleted again in the last test.
 */
final class RecordTest extends TestCase
{
    /**
     * Unregistered, like the other test domain.
     */
    private string $domain = 'sjaskende-rabarber-robot.dk';

    private function zone(): Zone
    {
        return $this->quickDns->getZone($this->domain);
    }

    public function test_create_the_zone()
    {
        (new Zone($this->quickDns, $this->domain))->create();

        $records = $this->zone()->getRecords();

        $this->assertCount(4, $records, 'A new zone has the four NS records of "QuickDNS global".');
        $this->assertTrue($records[0]->isLocked());
        $this->assertSame('QuickDNS global', $records[0]->template);
    }

    public function test_add_records_in_one_session()
    {
        $this->zone()->edit(function (RecordSet $records) {
            $records->add('www', 'A', '192.0.2.10', ttl: 3600);
            $records->add('@', RecordType::MX, 'mx1.example.dk.', ttl: 3600, priority: 10);
            $records->add('@', RecordType::TXT, 'v=spf1 include:_spf.example.dk ~all', ttl: 900);
        });

        $records = $this->zone()->getRecords();
        $summary = array_map(fn (Record $r) => "{$r->name} {$r->type} {$r->ttl} {$r->priority} {$r->value}", $records);

        $this->assertCount(7, $records);
        $this->assertContains('www A 3600  192.0.2.10', $summary);
        $this->assertContains('@ MX 3600 10 mx1.example.dk.', $summary);
        $this->assertContains('@ TXT 900  v=spf1 include:_spf.example.dk ~all', $summary);
    }

    public function test_change_and_remove_in_one_session()
    {
        $this->zone()->edit(function (RecordSet $records) {
            $www = $records->sole(name: 'www', type: RecordType::A);
            $records->replace($www, $www->withValue('192.0.2.20')->withTtl(300));
            $records->remove($records->sole(name: '@', type: RecordType::TXT));
        });

        $records = $this->zone()->getRecords();
        $www = array_values(array_filter($records, fn (Record $r) => $r->name === 'www'))[0];

        $this->assertCount(6, $records);
        $this->assertSame(['192.0.2.20', 300], [$www->value, $www->ttl]);
        $this->assertSame([], array_filter($records, fn (Record $r) => $r->type === 'TXT'));
    }

    public function test_changing_the_type_clears_the_priority()
    {
        $this->zone()->edit(function (RecordSet $records) {
            $mx = $records->sole(name: '@', type: RecordType::MX);
            $records->replace($mx, $mx->withType(RecordType::A)->withValue('192.0.2.30'));
        });

        $changed = $this->zone()->getRecords();
        $record = array_values(array_filter($changed, fn (Record $r) => $r->value === '192.0.2.30'))[0];

        $this->assertSame(['A', null], [$record->type, $record->priority]);
    }

    public function test_a_rejected_record_saves_nothing_at_all()
    {
        $before = $this->zone()->getRecords();

        try {
            $this->zone()->edit(function (RecordSet $records) {
                $records->add('accepted', 'A', '192.0.2.40');
                // QuickDNS rejects this, and the library only knows after asking.
                $records->addRecord(new Record('rejected', 'A', 3600, null, str_repeat('x', 300)));
            });
            $this->fail('Expected RecordRejected.');
        } catch (RecordRejected $e) {
            $this->assertNotSame([], $e->errors());
        }

        $this->assertSame(
            array_map(fn (Record $r) => $r->value, $before),
            array_map(fn (Record $r) => $r->value, $this->zone()->getRecords()),
            'Not even the accepted record is saved.',
        );
    }

    public function test_an_exception_in_the_closure_discards()
    {
        $before = count($this->zone()->getRecords());

        try {
            $this->zone()->edit(function (RecordSet $records) {
                $records->add('discarded', 'A', '192.0.2.50');
                throw new \RuntimeException('changed my mind');
            });
            $this->fail('Expected the closure to throw.');
        } catch (\RuntimeException) {
        }

        $this->assertCount($before, $this->zone()->getRecords());
        // The next session must start clean, which is what an abandoned session guarantees.
        $this->assertNull($this->zone()->edit(fn (RecordSet $records) => $records->find(name: 'discarded')));
    }

    public function test_template_records_cannot_be_changed()
    {
        $this->expectException(RecordLocked::class);
        $this->zone()->edit(function (RecordSet $records) {
            $records->remove($records->sole(type: RecordType::NS, value: 'ns1.quickdns.dk.'));
        });
    }

    public function test_duplicates_are_refused_unless_asked_for()
    {
        $this->zone()->edit(function (RecordSet $records) {
            $existing = $records->sole(name: 'www', type: RecordType::A);

            try {
                $records->add($existing->name, $existing->type, $existing->value, $existing->ttl);
                $this->fail('Expected InvalidRecord.');
            } catch (InvalidRecord $e) {
                $this->assertStringContainsString('already has this record', $e->getMessage());
            }
        });

        $this->assertTrue(true);
    }

    public function test_one_shot_helpers_and_cleanup()
    {
        $zone = $this->zone();
        $added = $zone->addRecord('single', 'A', '192.0.2.60', ttl: 3600);
        $this->assertSame('single', $added->name);

        $zone->deleteRecord($zone->getRecords()[array_search('single', array_map(fn (Record $r) => $r->name, $zone->getRecords()), true)]);
        $this->assertSame([], array_filter($zone->getRecords(), fn (Record $r) => $r->name === 'single'));

        $zone->delete();
        $this->assertSame([], array_filter($this->quickDns->getZones(), fn (Zone $z) => $z->domain === $this->domain));
    }
}

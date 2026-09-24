<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\InvalidRecord;
use QuickDns\Exceptions\RecordRejected;
use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
use QuickDns\Template;
use QuickDns\Testing\FakeQuickDns;
use QuickDns\Zone;

/**
 * The bugs 2.5.0 and 2.6.0 shipped with, each with the scenario that found it.
 */
final class RegressionTest extends TestCase
{
    public function test_two_templates_added_through_the_same_zone_object()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('first');
        $fake->addTemplate('second');
        $fake->addZone('example.dk');
        $quickDns = $fake->quickDns();
        $zone = $quickDns->getZone('example.dk');

        $quickDns->getTemplate('first')->addZone($zone);
        $quickDns->getTemplate('second')->addZone($zone);

        // The zone object carried the ids it was read with, so the second add used to replace the
        // first instead of joining it.
        $this->assertSame(['first', 'second'], $fake->templatesOf('example.dk'));
    }

    public function test_two_groups_added_through_the_same_zone_object()
    {
        $fake = new FakeQuickDns();
        $fake->addGroup('one');
        $fake->addGroup('two');
        $fake->addZone('example.dk');
        $quickDns = $fake->quickDns();
        $zone = $quickDns->getZone('example.dk');

        $quickDns->getGroup('one')->addZone($zone);
        $quickDns->getGroup('two')->addZone($zone);

        $this->assertSame(['one', 'two'], $fake->groupsOf('example.dk'));
    }

    public function test_a_failed_open_does_not_block_the_next_edit()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('example.dk');
        $quickDns = $fake->quickDns();

        try {
            // No such zone, so opening fails.
            $quickDns->editZone(999999, fn (RecordSet $records) => null);
            $this->fail('Expected UnrecognisedPage.');
        } catch (UnrecognisedPage) {
        }

        $this->assertCount(4, $quickDns->editZone($quickDns->getZone('example.dk'), fn (RecordSet $records) => $records->all()));
    }

    public function test_catching_a_rejection_does_not_report_success()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('example.dk');
        $quickDns = $fake->quickDns();
        $zone = $quickDns->getZone('example.dk');
        $fake->failNextChange('Noget gik galt.');

        try {
            $zone->edit(function (RecordSet $records) {
                try {
                    $records->add('rejected', 'A', '192.0.2.1');
                } catch (RecordRejected) {
                    // "I'll just carry on then": QuickDNS will not, and neither may we.
                }
            });
            $this->fail('Expected the rejection to surface from edit().');
        } catch (RecordRejected $e) {
            $this->assertSame('Noget gik galt.', $e->getMessage());
        }

        $this->assertCount(4, $fake->recordsOf('example.dk'));
    }

    public function test_two_mx_records_differing_only_in_priority()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('example.dk');
        $fake->addRecord('example.dk', '@', 'MX', 'mx.example.dk.', 3600, 10);
        $fake->addRecord('example.dk', '@', 'MX', 'mx.example.dk.', 3600, 20);
        $zone = $fake->quickDns()->getZone('example.dk');
        $records = $zone->getRecords();
        $twenty = array_values(array_filter($records, fn (Record $r) => $r->priority === 20))[0];

        $zone->deleteRecord($twenty);

        $left = array_values(array_filter($zone->getRecords(), fn (Record $r) => $r->type === 'MX'));
        $this->assertCount(1, $left);
        $this->assertSame(10, $left[0]->priority);
    }

    public function test_deleting_another_row_does_not_forgive_a_rejected_one()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('example.dk');
        $fake->addRecord('example.dk', 'www', 'A', '192.0.2.1');
        $quickDns = $fake->quickDns();
        $zone = $quickDns->getZone('example.dk');
        $fake->failNextChange('Noget gik galt.');

        try {
            $zone->edit(function (RecordSet $records) {
                try {
                    $records->add('rejected', 'A', '192.0.2.2');
                } catch (RecordRejected) {
                }
                $records->remove($records->sole(name: 'www', type: RecordType::A));
            });
            $this->fail('Expected the rejection to surface.');
        } catch (RecordRejected) {
        }

        // The live service saves nothing from such a session, so neither does the fake.
        $this->assertCount(5, $fake->recordsOf('example.dk'));
    }

    public function test_an_unknown_record_is_not_silently_the_wrong_one()
    {
        $fake = new FakeQuickDns();
        $fake->addZone('example.dk');
        $zone = $fake->quickDns()->getZone('example.dk');

        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('0 records match www A 192.0.2.1, not one.');
        $zone->deleteRecord(new Record('www', 'A', 3600, null, '192.0.2.1'));
    }

}

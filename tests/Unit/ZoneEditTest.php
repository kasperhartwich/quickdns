<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\InvalidRecord;
use QuickDns\Exceptions\RecordLocked;
use QuickDns\Exceptions\RecordRejected;
use QuickDns\Exceptions\StaleRecord;
use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
use QuickDns\Zone;

final class ZoneEditTest extends TestCase
{
    private function zone(array $fixtures): Zone
    {
        $zone = new Zone($this->quickDns($fixtures), 'flyvende-agurk-pingvin.dk');
        $zone->id = 17363;

        return $zone;
    }

    /**
     * The session key the recorded zone page carries.
     */
    private function zkey(): string
    {
        preg_match("/init\('([0-9a-f]+)'/", $this->fixture('editzone'), $match);

        return $match[1];
    }

    private function lastUri(): string
    {
        $uris = $this->uris();

        return (string) end($uris);
    }

    /**
     * @return string[] The requests made, relative to https://www.quickdns.dk/
     */
    private function uris(): array
    {
        return array_map(fn ($entry) => substr((string) $entry['request']->getUri(), strlen('https://www.quickdns.dk/')), $this->history);
    }

    public function test_add_a_record()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-insert', 'editzonedone-saved']);

        $added = $zone->edit(fn (RecordSet $records) => $records->add('zulu', 'A', '192.0.2.26', ttl: 3600));

        $this->assertSame(['zulu', 'A', 3600, null, '192.0.2.26', 5], [$added->name, $added->type, $added->ttl, $added->priority, $added->value, $added->row]);
        $zkey = $this->zkey();
        $this->assertSame([
            'login',
            'editzone?id=17363',
            'submitzonechange?action=initial&seq=0&zkey='.$zkey,
            'submitzonechange?seq=1&zkey='.$zkey.'&action=edit&row=-1&record=zulu&ttl=3600&type=A&priority=&value=192.0.2.26',
            'editzonedone?save=1&seq=1&zkey='.$zkey,
        ], $this->uris());
    }

    public function test_reading_only_sends_no_change_and_no_save()
    {
        $zone = $this->zone(['editzone']);

        $names = $zone->edit(fn (RecordSet $records) => array_map(fn (Record $r) => $r->name, $records->all()));

        $this->assertSame(['@', '@', '@', '@', '@', '@', '@', '@', '@', '*', 'www'], $names);
        $this->assertSame(['login', 'editzone?id=17363'], $this->uris());
    }

    public function test_change_and_delete_address_the_current_row()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-insert', 'submitzonechange-change', 'submitzonechange-delete', 'editzonedone-saved']);

        $zone->edit(function (RecordSet $records) {
            $added = $records->add('zulu', 'A', '192.0.2.26', ttl: 3600);
            $changed = $records->replace($added, $added->withValue('192.0.2.99')->withTtl(900));
            $records->remove($records->sole(name: 'www', type: RecordType::CNAME));

            return $changed;
        });

        $uris = $this->uris();
        $this->assertStringContainsString('action=edit&row=5&record=zulu&ttl=900&type=A&priority=&value=192.0.2.99', $uris[4]);
        // www sat on row 11 in the page, but the insert pushed it down to 12.
        $this->assertStringContainsString('action=delete&row=12', $uris[5]);
    }

    public function test_the_saved_record_is_what_quickdns_reports()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-insert-mx', 'editzonedone-saved']);

        $added = $zone->edit(fn (RecordSet $records) => $records->add('@', RecordType::MX, 'mx3.example.dk.', ttl: 3600, priority: 10));

        // The record that comes back is the one QuickDNS reports, on the row it chose, not the one
        // that was sent: the recorded answer is from an insert of mx1.
        $this->assertSame(['@', 'MX', 3600, 10, 'mx1.example.dk.', 8], [$added->name, $added->type, $added->ttl, $added->priority, $added->value, $added->row]);
    }

    public function test_a_rejected_record_discards_the_whole_session()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-error', 'editzonedone-discarded']);

        try {
            $zone->edit(function (RecordSet $records) {
                $records->add('bad', 'TXT', 'rodgrod', ttl: 3600);
            });
            $this->fail('Expected RecordRejected.');
        } catch (RecordRejected $e) {
            $this->assertSame("'rødgrød' indeholder ugyldige tegn.", $e->getMessage());
            $this->assertSame('Status: 1 fejl i zonen', $e->status());
            $this->assertSame([11], $e->rows());
            $this->assertSame('bad', $e->attempted()->name);
        }

        $this->assertStringContainsString('editzonedone?save=0&seq=1', $this->lastUri());
    }

    public function test_an_exception_in_the_closure_discards()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-insert', 'editzonedone-discarded']);

        try {
            $zone->edit(function (RecordSet $records) {
                $records->add('zulu', 'A', '192.0.2.26', ttl: 3600);
                throw new \RuntimeException('changed my mind');
            });
            $this->fail('Expected the closure to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('changed my mind', $e->getMessage());
        }

        $this->assertStringContainsString('editzonedone?save=0&seq=1', $this->lastUri());
    }

    public function test_duplicates_are_refused_unless_asked_for()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-duplicate', 'editzonedone-saved']);

        $zone->edit(function (RecordSet $records) {
            try {
                $records->add('www', RecordType::CNAME, '@', ttl: 300);
                $this->fail('Expected InvalidRecord.');
            } catch (InvalidRecord $e) {
                $this->assertStringContainsString('already has this record', $e->getMessage());
            }

            $records->add('www', RecordType::CNAME, '@', ttl: 300, allowDuplicates: true);
        });

        $this->assertCount(5, $this->history);
    }

    public function test_template_records_cannot_be_touched()
    {
        $zone = $this->zone(['editzone']);

        $zone->edit(function (RecordSet $records) {
            $ns = $records->sole(type: RecordType::NS, value: 'ns1.quickdns.dk.');
            $this->assertTrue($ns->isLocked());

            $this->expectException(RecordLocked::class);
            $records->remove($ns);
        });
    }

    public function test_a_record_from_before_a_change_is_stale()
    {
        $zone = $this->zone(['editzone', 'submitzonechange-initial', 'submitzonechange-insert', 'submitzonechange-change', 'editzonedone-discarded']);

        $this->expectException(StaleRecord::class);
        $zone->edit(function (RecordSet $records) {
            $added = $records->add('zulu', 'A', '192.0.2.26', ttl: 3600);
            $records->replace($added, $added->withValue('192.0.2.99')->withTtl(900));
            $records->remove($added);
        });
    }

    public function test_validation_happens_before_any_request()
    {
        $zone = $this->zone(['editzone', 'editzonedone-discarded']);

        $zone->edit(function (RecordSet $records) {
            foreach ([
                ['www', 'A', 'say "hi"', null, null, 'rejects "'],
                ['www', 'A', "it's", null, null, "rejects '"],
                ['www', 'A', 'rødgrød', null, null, 'printable ASCII'],
                ['www', 'A', '192.0.2.1', null, 10, 'MX and SRV records only'],
                ['www', 'NOPE', '192.0.2.1', null, null, 'no record type'],
                ['', 'A', '192.0.2.1', null, null, 'needs a name'],
                ['www', 'A', '192.0.2.1', 0, null, 'positive number of seconds'],
            ] as [$name, $type, $value, $ttl, $priority, $expected]) {
                try {
                    $records->add($name, $type, $value, $ttl, $priority);
                    $this->fail('Expected InvalidRecord for '.$expected);
                } catch (InvalidRecord $e) {
                    $this->assertStringContainsString($expected, $e->getMessage());
                }
            }
        });

        $this->assertSame(['login', 'editzone?id=17363'], $this->uris());
    }

    public function test_a_page_without_a_session_key()
    {
        $zone = $this->zone([str_replace("init('", "nope('", $this->fixture('editzone'))]);

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('No edit session key on the zone page for zone 17363');
        $zone->edit(fn (RecordSet $records) => null);
    }

    public function test_the_set_dies_with_the_edit()
    {
        $zone = $this->zone(['editzone']);
        $escaped = $zone->edit(fn (RecordSet $records) => $records);

        $this->expectException(\LogicException::class);
        $escaped->all();
    }

    public function test_nested_edits_are_refused()
    {
        $zone = $this->zone(['editzone']);

        $this->expectException(\LogicException::class);
        $zone->edit(fn (RecordSet $records) => $zone->edit(fn (RecordSet $inner) => null));
    }

    public function test_a_zone_without_an_id()
    {
        $zone = new Zone($this->quickDns(), 'flyvende-agurk-pingvin.dk');

        $this->expectException(\BadFunctionCallException::class);
        $zone->edit(fn (RecordSet $records) => null);
    }
}

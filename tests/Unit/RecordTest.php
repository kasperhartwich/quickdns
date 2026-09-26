<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Record;
use QuickDns\Zone;

final class RecordTest extends TestCase
{
    /**
     * @return Record[]
     */
    private function records(): array
    {
        $zone = new Zone($this->quickDns(['editzone']), 'flyvende-agurk-pingvin.dk', 17363);

        return $zone->getRecords();
    }

    public function test_reads_the_zone_page()
    {
        $this->records();

        $this->assertSame('editzone?id=17363', $this->lastRequestUri());
    }

    public function test_reads_every_record_in_page_order()
    {
        $summary = array_map(fn (Record $r) => "{$r->name} {$r->type} {$r->value}", $this->records());

        $this->assertSame([
            '@ NS ns1.quickdns.dk.',
            '@ NS ns2.quickdns.dk.',
            '@ NS ns3.quickdns.dk.',
            '@ NS ns4.quickdns.dk.',
            '@ A 192.0.2.10',
            '@ AAAA 2001:db8::10',
            '@ MX mx1.example.dk.',
            '@ MX mx2.example.dk.',
            '@ TXT v=spf1 include:_spf.example.dk ~all '.trim(str_repeat('lang-tekst-uden-specialtegn ', 10)),
            '* A 192.0.2.11',
            'www CNAME @',
        ], $summary);
    }

    public function test_template_records()
    {
        $ns = $this->records()[0];

        $this->assertNull($ns->ttl, 'NS records from the template have no TTL of their own.');
        $this->assertNull($ns->priority);
        $this->assertSame('QuickDNS global', $ns->template);
        $this->assertSame(1, $ns->row, 'The header is row 0.');
    }

    public function test_own_records()
    {
        $records = $this->records();

        $this->assertSame(3600, $records[4]->ttl);
        $this->assertNull($records[4]->template);
        $this->assertSame(5, $records[4]->row);
        $this->assertSame(300, $records[10]->ttl);
        $this->assertNull($records[9]->ttl, 'The wildcard was added without a TTL.');
    }

    public function test_mx_priority()
    {
        $records = $this->records();

        $this->assertSame([10, 20], [$records[6]->priority, $records[7]->priority]);
        $this->assertNull($records[4]->priority);
    }

    public function test_long_value_comes_from_the_title()
    {
        $page = $this->fixture('editzone');
        // Make the cell text shorter than the title, as QuickDNS may for long values.
        $page = preg_replace('/(<td title="v=spf1[^"]*">)v=spf1[^<]*/', '$1v=spf1 include:...', $page);
        $zone = new Zone($this->quickDns([$page]), 'flyvende-agurk-pingvin.dk', 17363);

        $this->assertStringEndsWith('lang-tekst-uden-specialtegn', $zone->getRecords()[8]->value);
    }

    public function test_empty_table_is_no_records()
    {
        $this->assertSame([], $this->quickDns(['editzone-empty'])->getRecords(17363));
    }

    public function test_missing_table_throws_unrecognised_page()
    {
        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('No record table on the zone page for zone 17363');
        $this->quickDns(['zones-empty'])->getRecords(17363);
    }

    public function test_logged_out_page_throws_unrecognised_page()
    {
        $this->expectException(UnrecognisedPage::class);
        $this->quickDns(['login-failed', 'login-ok', 'login-failed'])->getRecords(17363);
    }

    public function test_zone_without_id()
    {
        $this->expectException(\QuickDns\Exceptions\MissingId::class);
        (new Zone($this->quickDns(), 'flyvende-agurk-pingvin.dk'))->getRecords();
    }
}

<?php

namespace QuickDns\Parsing;

use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Record;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The record table on a zone page, read into Record objects.
 *
 * QuickDNS addresses a record by its row number in that table, counting the header as row 0, so
 * the parser keeps the row numbers rather than a plain list.
 *
 * @internal
 */
final class ZoneTable
{
    /**
     * @param  array<int, Record>  $records  Keyed by row number
     */
    private function __construct(private array $records)
    {
    }

    /**
     * Read the record table out of a zone page.
     *
     * @throws UnrecognisedPage when the page has no record table
     */
    public static function fromPage(Crawler $page, string $what): self
    {
        // The zones list uses the same table id; only the record table has the class "records".
        $table = $page->filterXPath('//table[@id="zone_table" and contains(concat(" ", normalize-space(@class), " "), " records ")]');
        if (! $table->count()) {
            throw new UnrecognisedPage('No record table on the zone page for '.$what);
        }

        $records = [];
        foreach ($table->filterXPath('.//tr') as $row => $tr) {
            $cells = $tr->getElementsByTagName('td');
            if ($cells->length < 5) {
                continue;
            }
            $text = fn (int $i) => trim($cells->item($i)->textContent);
            $title = $cells->item(4)->getAttribute('title');
            $template = null;
            if ($cells->length > 5 && preg_match('/skabelonen "([^"]*)"/', $cells->item(5)->getAttribute('title'), $match)) {
                $template = $match[1];
            }
            $records[$row] = new Record(
                $text(0),
                $text(2),
                $text(1) === '' ? null : (int) $text(1),
                $text(3) === '' ? null : (int) $text(3),
                // The cell may shorten a long value; the title holds all of it.
                $title !== '' ? trim($title) : $text(4),
                $row,
                $template,
            );
        }

        return new self($records);
    }

    /**
     * The records in page order.
     *
     * @return Record[]
     */
    public function records(): array
    {
        return array_values($this->records);
    }
}

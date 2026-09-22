<?php

namespace QuickDns;

/**
 * One DNS record as QuickDNS shows it on the zone page.
 */
final class Record
{
    /**
     * @param  string  $name  As on the page, relative to the zone: "@", "www", "*"
     * @param  string  $type  A, AAAA, CNAME, MX, NS, PTR, SPF, SRV or TXT
     * @param  int|null  $ttl  Null when the record has no TTL of its own (NS records from a template)
     * @param  int|null  $priority  Only MX and SRV records have one
     * @param  string  $value  The full value, e.g. "mx1.example.dk." or "@"
     * @param  int  $row  The record's row on the zone page (the header is row 0), which QuickDNS
     *                    uses to address a record when editing or deleting it
     * @param  string|null  $template  Name of the template that added the record, or null when it
     *                                 was added to the zone itself. Template records cannot be
     *                                 edited on the zone.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $ttl,
        public readonly ?int $priority,
        public readonly string $value,
        public readonly int $row,
        public readonly ?string $template = null,
    ) {
    }
}

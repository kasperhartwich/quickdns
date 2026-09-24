<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;

/**
 * The record types QuickDNS offers. Generated from the site's own type dropdown.
 */
enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case NS = 'NS';
    case PTR = 'PTR';
    case SPF = 'SPF';
    case SRV = 'SRV';
    case TXT = 'TXT';

    /**
     * QuickDNS keeps a priority for these two only, and clears it for every other type.
     */
    public function usesPriority(): bool
    {
        return $this === self::MX || $this === self::SRV;
    }

    /**
     * @throws InvalidRecord when QuickDNS has no such type
     */
    public static function parse(self|string $type): self
    {
        if ($type instanceof self) {
            return $type;
        }

        return self::tryFrom(strtoupper(trim($type)))
            ?? throw new InvalidRecord('QuickDNS has no record type '.$type.'. Use one of: '.implode(', ', array_column(self::cases(), 'value')).'.');
    }
}

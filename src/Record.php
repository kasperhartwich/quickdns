<?php

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;

/**
 * One DNS record as QuickDNS shows it on the zone page.
 */
final class Record
{
    /**
     * The TTLs QuickDNS' own dropdown offers. Other values are accepted too, so this is a list of
     * suggestions, not a rule.
     */
    public const TTLS = [300, 900, 1800, 3600, 10800];

    /**
     * Characters QuickDNS rejects in a name or a value ("indeholder ugyldige tegn"). Everything
     * else has to be plain printable ASCII: Danish letters are rejected too, even though they are
     * fine in a template or group name.
     */
    public const INVALID_CHARACTERS = ['"', "'", '\\'];

    /**
     * @param  string  $name  As on the page, relative to the zone: "@", "www", "*"
     * @param  string  $type  A, AAAA, CNAME, MX, NS, PTR, SPF, SRV or TXT
     * @param  int|null  $ttl  Null when the record has no TTL of its own (NS records from a template)
     * @param  int|null  $priority  Only MX and SRV records have one
     * @param  string  $value  The full value, e.g. "mx1.example.dk." or "@"
     * @param  int|null  $row  The record's row on the zone page (the header is row 0), which QuickDNS
     *                         uses to address a record when editing or deleting it. Null for a
     *                         record that is not on a page yet.
     * @param  string|null  $template  Name of the template that added the record, or null when it
     *                                 was added to the zone itself. Template records cannot be
     *                                 edited or deleted on the zone.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $ttl,
        public readonly ?int $priority,
        public readonly string $value,
        public readonly ?int $row = null,
        public readonly ?string $template = null,
    ) {
    }

    /**
     * A record to add to a zone. Validates it, so a mistake surfaces before any request.
     *
     * @throws InvalidRecord
     */
    public static function make(string $name, RecordType|string $type, string $value, ?int $ttl = null, ?int $priority = null): self
    {
        $record = new self($name, RecordType::parse($type)->value, $ttl, $priority, $value);
        $record->validate();

        return $record;
    }

    public function withName(string $name): self
    {
        return new self($name, $this->type, $this->ttl, $this->priority, $this->value, $this->row, $this->template);
    }

    /**
     * The new type decides whether the priority survives: QuickDNS clears it for every type but
     * MX and SRV.
     */
    public function withType(RecordType|string $type): self
    {
        $type = RecordType::parse($type);

        return new self($this->name, $type->value, $this->ttl, $type->usesPriority() ? $this->priority : null, $this->value, $this->row, $this->template);
    }

    public function withTtl(?int $ttl): self
    {
        return new self($this->name, $this->type, $ttl, $this->priority, $this->value, $this->row, $this->template);
    }

    public function withPriority(?int $priority): self
    {
        return new self($this->name, $this->type, $this->ttl, $priority, $this->value, $this->row, $this->template);
    }

    public function withValue(string $value): self
    {
        return new self($this->name, $this->type, $this->ttl, $this->priority, $value, $this->row, $this->template);
    }

    /**
     * The type as an enum, or null when QuickDNS showed a type this library does not know.
     */
    public function type(): ?RecordType
    {
        return RecordType::tryFrom($this->type);
    }

    public function usesPriority(): bool
    {
        return $this->type()?->usesPriority() ?? false;
    }

    public function isFromTemplate(): bool
    {
        return $this->template !== null;
    }

    /**
     * Template records are shown with "-" instead of Ret and Slet: they can only be changed on the
     * template itself.
     */
    public function isLocked(): bool
    {
        return $this->isFromTemplate();
    }

    /**
     * Same record, ignoring the row it happens to sit on.
     */
    public function matches(self $other): bool
    {
        return $this->name === $other->name
            && $this->type === $other->type
            && $this->ttl === $other->ttl
            && $this->priority === $other->priority
            && $this->value === $other->value;
    }

    /**
     * @throws InvalidRecord when QuickDNS would reject this record
     */
    public function validate(): void
    {
        $type = RecordType::parse($this->type);

        foreach (['name' => $this->name, 'value' => $this->value] as $field => $text) {
            if (trim($text) === '') {
                throw new InvalidRecord('A record needs a '.$field.'.');
            }
            foreach (self::INVALID_CHARACTERS as $character) {
                if (str_contains($text, $character)) {
                    throw new InvalidRecord('QuickDNS rejects '.$character.' in a record '.$field.': '.$text);
                }
            }
            if (preg_match('/[^\x20-\x7e]/', $text)) {
                throw new InvalidRecord('QuickDNS rejects anything but printable ASCII in a record '.$field.': '.$text);
            }
        }

        if ($this->priority !== null && ! $type->usesPriority()) {
            throw new InvalidRecord('QuickDNS keeps a priority for MX and SRV records only, not '.$type->value.'.');
        }
        if ($this->priority !== null && ($this->priority < 0 || $this->priority > 65535)) {
            throw new InvalidRecord('A priority is between 0 and 65535, not '.$this->priority.'.');
        }
        if ($this->ttl !== null && $this->ttl < 1) {
            throw new InvalidRecord('A TTL is a positive number of seconds, not '.$this->ttl.'.');
        }
    }
}

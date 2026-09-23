<?php

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;
use QuickDns\Exceptions\RecordLocked;
use QuickDns\Exceptions\StaleRecord;
use QuickDns\Internal\ZoneEditSession;

/**
 * The records of a zone while it is being edited.
 *
 * Every change is sent to QuickDNS as it is made, and the whole set is saved, or thrown away, when
 * the closure passed to Zone::edit() returns. The set is only usable inside that closure.
 */
final class RecordSet implements \Countable, \IteratorAggregate
{
    private bool $closed = false;

    /**
     * @internal
     */
    public function __construct(private readonly ZoneEditSession $session)
    {
    }

    /**
     * Add a record.
     *
     * QuickDNS accepts two identical records without a word, which is how a retried write ends up
     * duplicated, so an identical record is refused unless you ask for it.
     *
     * @throws InvalidRecord when QuickDNS would reject the record, or it is already there
     */
    public function add(string $name, RecordType|string $type, string $value, ?int $ttl = null, ?int $priority = null, bool $allowDuplicates = false): Record
    {
        return $this->addRecord(Record::make($name, $type, $value, $ttl, $priority), $allowDuplicates);
    }

    /**
     * Add a record. Its row and template are ignored: QuickDNS decides those.
     *
     * @throws InvalidRecord
     */
    public function addRecord(Record $record, bool $allowDuplicates = false): Record
    {
        $this->open();
        $record->validate();
        if (! $allowDuplicates && $this->contains($record)) {
            throw new InvalidRecord('The zone already has this record: '.$this->describe($record).'. Pass allowDuplicates: true to add it anyway.');
        }

        $this->session->change([
            'action' => 'edit',
            'row' => -1,
            'record' => $record->name,
            'ttl' => $record->ttl ?? '',
            'type' => $record->type,
            'priority' => $record->priority ?? '',
            'value' => $record->value,
        ], $record);

        return $this->changed($record);
    }

    /**
     * Change a record. Returns the record as it now stands, which is what later changes must use.
     *
     * @throws StaleRecord when the record is no longer in the set
     * @throws RecordLocked when a template added it
     * @throws InvalidRecord
     */
    public function replace(Record $record, Record $with): Record
    {
        $this->open();
        $row = $this->row($record);
        $with->validate();

        $this->session->change([
            'action' => 'edit',
            'row' => $row,
            'record' => $with->name,
            'ttl' => $with->ttl ?? '',
            'type' => $with->type,
            'priority' => $with->priority ?? '',
            'value' => $with->value,
        ], $with);

        return $this->changed($with);
    }

    /**
     * Remove a record.
     *
     * @throws StaleRecord when the record is no longer in the set
     * @throws RecordLocked when a template added it
     */
    public function remove(Record $record): void
    {
        $this->open();
        $this->session->change(['action' => 'delete', 'row' => $this->row($record)], $record);
    }

    /**
     * The records, in the order QuickDNS lists them.
     *
     * @return Record[]
     */
    public function all(): array
    {
        $this->open();

        return $this->session->records();
    }

    /**
     * The records matching every argument given.
     *
     * @return Record[]
     */
    public function where(?string $name = null, RecordType|string|null $type = null, ?string $value = null): array
    {
        $type = $type === null ? null : RecordType::parse($type)->value;

        return array_values(array_filter($this->all(), fn (Record $record) => ($name === null || $record->name === $name)
            && ($type === null || $record->type === $type)
            && ($value === null || $record->value === $value)));
    }

    /**
     * The first record matching every argument given, or null.
     */
    public function find(?string $name = null, RecordType|string|null $type = null, ?string $value = null): ?Record
    {
        return $this->where($name, $type, $value)[0] ?? null;
    }

    /**
     * The one record matching every argument given.
     *
     * @throws InvalidRecord when there is no such record, or more than one
     */
    public function sole(?string $name = null, RecordType|string|null $type = null, ?string $value = null): Record
    {
        $matches = $this->where($name, $type, $value);
        if (count($matches) !== 1) {
            throw new InvalidRecord(count($matches).' records match '.$this->describeSearch($name, $type, $value).', not one.');
        }

        return $matches[0];
    }

    public function contains(Record $record): bool
    {
        foreach ($this->all() as $existing) {
            if ($existing->matches($record)) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->all());
    }

    /**
     * @internal
     */
    public function close(): void
    {
        $this->closed = true;
    }

    private function row(Record $record): int
    {
        if ($record->isLocked()) {
            throw new RecordLocked('The template "'.$record->template.'" added '.$this->describe($record).', so it cannot be changed on the zone.');
        }

        return $this->session->rowOf($record)
            ?? throw new StaleRecord($this->describe($record).' is no longer in this zone. Use the record returned by replace(), or look it up again.');
    }

    /**
     * The record QuickDNS now has, so the caller can keep using it for further changes.
     */
    private function changed(Record $fallback): Record
    {
        $row = $this->session->lastChangedRow();

        return ($row === null ? null : $this->session->record($row)) ?? $fallback;
    }

    private function open(): void
    {
        if ($this->closed) {
            throw new \LogicException('This RecordSet belongs to an edit that has finished. Call edit() again.');
        }
    }

    private function describe(Record $record): string
    {
        return trim($record->name.' '.$record->type.' '.$record->value);
    }

    private function describeSearch(?string $name, RecordType|string|null $type, ?string $value): string
    {
        $parts = array_filter([
            $name === null ? null : 'name '.$name,
            $type === null ? null : 'type '.(is_string($type) ? $type : $type->value),
            $value === null ? null : 'value '.$value,
        ]);

        return $parts === [] ? 'no filter' : implode(', ', $parts);
    }
}

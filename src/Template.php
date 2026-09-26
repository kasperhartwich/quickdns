<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;

/**
 * A template on the account, as the templates page lists it, or one about to be created. A
 * template holds records like a zone, and every zone using it gets them.
 */
final readonly class Template extends BaseModel
{
    /**
     * @param  int  $zones  How many zones use it
     * @param  string[]  $groups  The names of the groups it is shared with
     * @param  \DateTimeImmutable|null  $updated  When QuickDNS last changed it, or null when the list does not say
     */
    public function __construct(
        private QuickDns $quickdns,
        public string $name,
        ?int $id = null,
        public int $zones = 0,
        public array $groups = [],
        public ?\DateTimeImmutable $updated = null,
    ) {
        parent::__construct($id);
    }

    /**
     * Create the template on QuickDNS.
     *
     * @return self The template with its id, ready to be edited or used
     */
    public function create(): self
    {
        $response = $this->quickdns->command('addtemplate', [
            'zone' => $this->name,
        ], QuickDns::METHOD_GET);

        // QuickDNS answers with the template's id in <zoneid>.
        $zoneid = $response->filterXPath('//response/zoneid');

        return new self($this->quickdns, $this->name, $zoneid->count() ? (int) trim($zoneid->text()) : null);
    }

    /**
     * Delete the template on QuickDNS. QuickDNS refuses while zones use it.
     */
    public function delete(): void
    {
        $this->quickdns->command('deltemplate', [
            'id' => $this->requireId(),
        ], QuickDns::METHOD_GET);
    }

    /**
     * Rename the template. Zones using it follow along, since they are tied to its id.
     *
     * @return self The template under its new name
     */
    public function rename(string $name): self
    {
        $this->quickdns->command('renametemplate', [
            'zone' => $this->requireId(),
            'name' => $name,
        ]);

        return new self($this->quickdns, $name, $this->id, $this->zones, $this->groups, $this->updated);
    }

    /**
     * The template's records, in the order QuickDNS lists them.
     *
     * @return Record[]
     */
    public function getRecords(): array
    {
        return $this->quickdns->getTemplateRecords($this->requireId());
    }

    /**
     * Change the template's records in one edit session, exactly like Zone::edit(). Every zone
     * using the template gets the changes.
     *
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed Whatever the closure returned
     */
    public function edit(callable $changes): mixed
    {
        return $this->quickdns->editTemplate($this->requireId(), $changes);
    }

    /**
     * Add one record to the template, in an edit session of its own.
     */
    public function addRecord(string $name, RecordType|string $type, string $value, ?int $ttl = null, ?int $priority = null, bool $allowDuplicates = false): Record
    {
        return $this->edit(fn (RecordSet $records) => $records->add($name, $type, $value, $ttl, $priority, $allowDuplicates));
    }

    /**
     * Change one record, in an edit session of its own. The record must come from getRecords().
     */
    public function replaceRecord(Record $record, Record $with): Record
    {
        return $this->edit(fn (RecordSet $records) => $records->replace(self::same($records, $record), $with));
    }

    /**
     * Delete one record, in an edit session of its own. The record must come from getRecords().
     */
    public function deleteRecord(Record $record): void
    {
        $this->edit(fn (RecordSet $records) => $records->remove(self::same($records, $record)));
    }

    /**
     * The same record inside the session. Every field counts, or two MX records differing only in
     * priority would be ambiguous.
     *
     * @throws InvalidRecord when the template has no such record, or more than one
     */
    private static function same(RecordSet $records, Record $record): Record
    {
        $matches = array_values(array_filter($records->all(), fn (Record $candidate) => $candidate->matches($record)));
        if (count($matches) !== 1) {
            throw new InvalidRecord(count($matches).' records match '.trim($record->name.' '.$record->type.' '.$record->value).', not one.');
        }

        return $matches[0];
    }

    /**
     * Put the template on a zone, keeping the zone's other templates. The zone's current list is
     * read from QuickDNS first, since QuickDNS replaces the whole list.
     *
     * @return Zone The zone as QuickDNS shows it afterwards
     */
    public function addZone(Zone $zone): Zone
    {
        $id = $this->requireId();
        $current = $this->quickdns->getZone($zone->domain);
        if (in_array($id, $current->templateIds ?? [], true)) {
            return $current;
        }

        $ids = [...$current->templateIds ?? [], $id];
        $this->quickdns->setTemplates($current, $ids);

        return $this->quickdns->getZone($current->domain);
    }

    /**
     * Take the template off a zone, leaving the zone's other templates alone.
     *
     * @return Zone The zone as QuickDNS shows it afterwards
     */
    public function removeZone(Zone $zone): Zone
    {
        $id = $this->requireId();
        $current = $this->quickdns->getZone($zone->domain);
        if (! in_array($id, $current->templateIds ?? [], true)) {
            return $current;
        }

        $ids = array_values(array_filter($current->templateIds ?? [], fn (int $other) => $other !== $id));
        $this->quickdns->setTemplates($current, $ids);

        return $this->quickdns->getZone($current->domain);
    }
}

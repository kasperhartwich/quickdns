<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;

/**
 * A zone on the account, as the zones page lists it, or one about to be created:
 *
 *     $zone = (new Zone($quickDns, 'example.dk'))->create();
 */
final readonly class Zone extends BaseModel
{
    /**
     * @param  string[]  $templates  The names of the templates the zone uses
     * @param  string[]  $groups  The names of the groups the zone is in
     * @param  \DateTimeImmutable|null  $updated  When QuickDNS last changed it, or null when the list does not say
     * @param  int[]|null  $templateIds  The ids of its templates, as the zones page carries them. Null when
     *                                   the zone did not come from that page, which is not the empty list.
     * @param  int[]|null  $groupIds  The ids of its groups, likewise
     */
    public function __construct(
        private QuickDns $quickdns,
        public string $domain,
        ?int $id = null,
        public array $templates = [],
        public array $groups = [],
        public ?\DateTimeImmutable $updated = null,
        public ?array $templateIds = null,
        public ?array $groupIds = null,
    ) {
        parent::__construct($id);
    }

    /**
     * Create the zone on QuickDNS.
     *
     * @return self The zone with its id, ready to be edited or attached
     */
    public function create(bool $get_data = false): self
    {
        $response = $this->quickdns->command('addzone', [
            'zone' => $this->domain,
            'getdata' => $get_data ? 1 : 0,
        ], QuickDns::METHOD_GET);

        $zoneid = $response->filterXPath('//response/zoneid');

        return new self($this->quickdns, $this->domain, $zoneid->count() ? (int) trim($zoneid->text()) : null, templateIds: [], groupIds: []);
    }

    /**
     * The templates the zone uses, read from the templates page.
     *
     * @return Template[]
     */
    public function templates(): array
    {
        $ids = $this->templateIds ?? $this->quickdns->getZone($this->domain)->templateIds ?? [];

        return array_values(array_filter(
            $this->quickdns->getTemplates(),
            fn (Template $template) => in_array($template->id, $ids, true),
        ));
    }

    /**
     * The groups the zone is in, read from the groups page.
     *
     * @return Group[]
     */
    public function groups(): array
    {
        $ids = $this->groupIds ?? $this->quickdns->getZone($this->domain)->groupIds ?? [];

        return array_values(array_filter(
            $this->quickdns->getGroups(),
            fn (Group $group) => in_array($group->id, $ids, true),
        ));
    }

    /**
     * Get the zone's records, in the order QuickDNS lists them.
     *
     * @return Record[]
     */
    public function getRecords(): array
    {
        return $this->quickdns->getRecords($this->requireId());
    }

    /**
     * Change the zone's records in one edit session. See QuickDns::editZone().
     *
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed Whatever the closure returned
     */
    public function edit(callable $changes): mixed
    {
        return $this->quickdns->editZone($this->requireId(), $changes);
    }

    /**
     * Add one record to the zone, in an edit session of its own.
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
     * The same record inside the session. Every field counts, or two MX records that differ only
     * in priority would be ambiguous.
     *
     * @throws InvalidRecord when the zone has no such record, or more than one
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
     * Delete the zone on QuickDNS.
     */
    public function delete(): void
    {
        $this->quickdns->command('delzone', [
            'id' => $this->requireId(),
        ], QuickDns::METHOD_GET);
    }
}

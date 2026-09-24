<?php

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;

/**
 * Class Zone
 *
 * @property string $domain
 * @property array $templates
 * @property array $groups
 */
class Zone extends BaseModel
{
    protected $quickdns;

    public $domain;

    public $templates;

    public $groups;

    /**
     * The ids of the templates the zone uses, as the zones page carries them. Null when the zone
     * did not come from that page, which is not the same as the empty list.
     *
     * @var int[]|null
     */
    public $templateIds = null;

    /**
     * The ids of the groups the zone is in, as the zones page carries them. Null when the zone did
     * not come from that page.
     *
     * @var int[]|null
     */
    public $groupIds = null;

    /**
     * Zone constructor.
     *
     * @param  null  $domain
     */
    public function __construct(QuickDns $quickdns, $domain = null)
    {
        $this->quickdns = $quickdns;
        $this->domain = $domain;
    }

    /**
     * Create Zone. Sets the zone's id, so it can be deleted or attached right away.
     *
     * @param  bool  $get_data
     * @return $this
     */
    public function create($get_data = false)
    {
        $response = $this->quickdns->command('addzone', [
            'zone' => $this->domain,
            'getdata' => $get_data ? 1 : 0,
        ], QuickDns::METHOD_GET);

        // A string, like the ids getZones() returns.
        $zoneid = $response->filterXPath('//response/zoneid');
        if ($zoneid->count()) {
            $this->id = trim($zoneid->text());
        }

        return $this;
    }

    /**
     * Get the zone's records, in the order QuickDNS lists them.
     *
     * @return Record[]
     */
    public function getRecords(): array
    {
        if (! $this->id) {
            throw new \BadFunctionCallException('Zone is not created yet.');
        }

        return $this->quickdns->getRecords($this);
    }

    /**
     * Change the zone's records in one edit session. See QuickDns::editZone().
     *
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed Whatever the closure returned
     */
    public function edit(callable $changes)
    {
        if (! $this->id) {
            throw new \BadFunctionCallException('Zone is not created yet.');
        }

        return $this->quickdns->editZone($this, $changes);
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
        return $this->edit(fn (RecordSet $records) => $records->replace($this->same($records, $record), $with));
    }

    /**
     * Delete one record, in an edit session of its own. The record must come from getRecords().
     */
    public function deleteRecord(Record $record): void
    {
        $this->edit(fn (RecordSet $records) => $records->remove($this->same($records, $record)));
    }

    /**
     * The same record inside the session. Every field counts, or two MX records that differ only
     * in priority would be ambiguous.
     *
     * @throws InvalidRecord when the zone has no such record, or more than one
     */
    private function same(RecordSet $records, Record $record): Record
    {
        $matches = array_values(array_filter($records->all(), fn (Record $candidate) => $candidate->matches($record)));
        if (count($matches) !== 1) {
            throw new InvalidRecord(count($matches).' records match '.trim($record->name.' '.$record->type.' '.$record->value).', not one.');
        }

        return $matches[0];
    }

    /**
     * Delete Zone
     *
     * @return bool
     */
    public function delete()
    {
        if (! $this->id) {
            throw new \BadFunctionCallException('Zone is not created yet.');
        }
        $this->quickdns->command('delzone', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);

        return true;
    }
}

<?php

namespace QuickDns;

use QuickDns\Exceptions\InvalidRecord;

/**
 * Class Template
 *
 * @property string $name
 * @property array $zones
 * @property array $groups
 */
class Template extends BaseModel
{
    protected $quickdns;

    public $name;

    public $zones;

    public $groups;

    /**
     * Template constructor.
     *
     * @param  null  $name
     */
    public function __construct(QuickDns $quickdns, $name = null)
    {
        $this->quickdns = $quickdns;
        $this->name = $name;
    }

    /**
     * Create Template. Sets the template's id, so it can be deleted or used right away.
     *
     * @return $this
     */
    public function create()
    {
        $response = $this->quickdns->command('addtemplate', [
            'zone' => $this->name,
        ], QuickDns::METHOD_GET);

        // QuickDNS answers with the template's id in <zoneid>.
        $zoneid = $response->filterXPath('//response/zoneid');
        if ($zoneid->count()) {
            $this->id = (int) trim($zoneid->text());
        }

        return $this;
    }

    /**
     * Delete Template
     *
     * @return bool
     */
    public function delete()
    {
        if (! $this->id) {
            throw new \BadFunctionCallException('Template is not created yet.');
        }
        $this->quickdns->command('deltemplate', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);

        return true;
    }

    /**
     * Rename the template. Zones using it follow along, since they are tied to its id.
     *
     * @return $this
     */
    public function rename(string $name)
    {
        $this->quickdns->command('renametemplate', [
            'zone' => $this->id ?: throw new \BadFunctionCallException('Template is not created yet.'),
            'name' => $name,
        ]);
        $this->name = $name;

        return $this;
    }

    /**
     * The template's records, in the order QuickDNS lists them.
     *
     * @return Record[]
     */
    public function getRecords(): array
    {
        return $this->quickdns->getTemplateRecords($this);
    }

    /**
     * Change the template's records in one edit session, exactly like Zone::edit(). Every zone
     * using the template gets the changes.
     *
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed Whatever the closure returned
     */
    public function edit(callable $changes)
    {
        return $this->quickdns->editTemplate($this, $changes);
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
     * The same record inside the session. Every field counts, or two MX records differing only in
     * priority would be ambiguous.
     *
     * @throws InvalidRecord when the template has no such record, or more than one
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
     * Add a zone to the template, keeping the zone's other templates.
     *
     * @return $this
     */
    public function addZone(Zone $zone)
    {
        $this->quickdns->setTemplates($zone, array_merge($this->templatesOf($zone), [$this]));

        return $this;
    }

    /**
     * Take a zone off the template, leaving the zone's other templates alone.
     *
     * @return $this
     */
    public function removeZone(Zone $zone)
    {
        $this->quickdns->setTemplates($zone, array_values(array_filter(
            $this->templatesOf($zone),
            fn (int $id) => $id !== $this->id,
        )));

        return $this;
    }

    /**
     * The ids of the templates the zone has right now. The zones page carries them, so no lookup
     * is needed for a zone that came from there.
     *
     * @return int[]
     */
    private function templatesOf(Zone $zone): array
    {
        if ($zone->templateIds !== null) {
            return $zone->templateIds;
        }

        return $this->quickdns->getZone($zone->domain)->templateIds;
    }
}

<?php

namespace QuickDns;

/**
 * Class Group
 *
 * @property string $name
 * @property array $members
 */
class Group extends BaseModel
{
    protected $quickdns;

    public $name;

    public $members = [];

    /**
     * Group constructor.
     *
     * @param  null  $name
     */
    public function __construct(QuickDns $quickdns, $name = null)
    {
        $this->quickdns = $quickdns;
        $this->name = $name;
    }

    /**
     * Create Group. QuickDNS does not answer with the new group's id, so fetch the group with
     * QuickDns::getGroup() before deleting it or adding zones.
     *
     * @return $this
     */
    public function create()
    {
        $this->quickdns->command('addgroup', [
            'group' => $this->name,
        ], QuickDns::METHOD_GET);

        return $this;
    }

    /**
     * Delete Group
     *
     * @return bool
     */
    public function delete()
    {
        if (! $this->id) {
            throw new \BadFunctionCallException('Group is not created yet.');
        }
        $this->quickdns->command('delgroup', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);

        return true;
    }

    /**
     * Add Zone to group
     * TODO: Support multiple groups
     *
     * @return $this
     */
    public function addZone(Zone $zone)
    {
        $this->quickdns->command('updategroups', [
            'zone' => $zone->id,
            'group' => $this->id,
        ]);

        return $this;
    }

    /**
     * Add Zone to group
     * TODO: Support multiple groups
     *
     * @return $this
     */
    public function removeZone(Zone $zone)
    {
        $this->quickdns->command('updategroups', [
            'zone' => $zone->id,
        ]);

        return $this;
    }
}

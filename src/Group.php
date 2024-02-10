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
     * Create Group
     *
     * @return $this
     */
    public function create()
    {
        $response = $this->quickdns->request('addgroup', [
            'group' => $this->name,
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

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
            throw new \BadFunctionCallException('Template is not created yet.');
        }
        $response = $this->quickdns->request('delgroup', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

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
        $response = $this->quickdns->request('updategroups', [
            'zone' => $zone->id,
            'group' => $this->id,
        ]);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

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
        $response = $this->quickdns->request('updategroups', [
            'zone' => $zone->id,
        ]);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

        return $this;
    }
}

<?php

namespace QuickDns;

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
     * Create Template
     *
     * @return $this
     */
    public function create()
    {
        $response = $this->quickdns->request('addtemplate', [
            'zone' => $this->name,
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
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
        $response = $this->quickdns->request('deltemplate', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

        return true;
    }

    /**
     * Add Zone to template
     * TODO: Support multiple templates
     *
     * @return $this
     */
    public function addZone(Zone $zone)
    {
        $response = $this->quickdns->request('updatetemplates', [
            'zone' => $zone->id,
            'template' => $this->id,
        ]);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

        return $this;
    }

    /**
     * Add Zone to template
     * TODO: Support multiple templates
     *
     * @return $this
     */
    public function removeZone(Zone $zone)
    {
        $response = $this->quickdns->request('updatetemplates', [
            'zone' => $zone->id,
        ]);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }

        return $this;
    }
}

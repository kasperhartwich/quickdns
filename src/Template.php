<?php
namespace QuickDns;

/**
 * Class Template
 * @property integer $id
 * @property string $name
 * @property array $zones
 * @property array $groups
 * @property string $updated
 * @package QuickDns
 */
class Template
{
    protected $quickdns;

    public $id;
    public $name;
    public $zones;
    public $groups;
    public $updated;

    /**
     * Template constructor.
     * @param QuickDns $quickdns
     * @param null $name
     */
    public function __construct(QuickDns $quickdns, $name = null)
    {
        $this->quickdns = $quickdns;
        $this->name = $name;
    }

    /**
     * Create Template
     * @return bool
     */
    public function create()
    {
        $response = $this->quickdns->request('addtemplate', [
            'zone' => $this->name
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }
        return true;
    }

    /**
     * Delete Template
     * @return bool
     */
    public function delete()
    {
        if (!$this->id) {
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
     * @param Zone $zone
     * @return bool
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
        return true;
    }

    /**
     * Add Zone to template
     * TODO: Support multiple templates
     * @param Zone $zone
     * @return bool
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
        return true;
    }
}

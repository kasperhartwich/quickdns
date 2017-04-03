<?php
namespace QuickDns;

/**
 * Class Template
 * @package QuickDns
 */
class Template
{
    protected $quickdns;

    public $id;
    public $name;
    public $group;
    public $zones;
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
}

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
    public $updated;

    /**
     * Zone constructor.
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
     * @param bool $get_data
     * @return bool
     */
    public function create($get_data = false)
    {
        $response = $this->quickdns->request('addtemplate', [
            'zone' => $this->name,
            'getdata' => $get_data ? 1 : 0,
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

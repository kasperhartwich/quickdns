<?php
namespace QuickDns;

/**
 * Class Group
 * @package QuickDns
 */
class Group
{
    protected $quickdns;

    public $id;
    public $name;
    public $updated;

    /**
     * Group constructor.
     * @param QuickDns $quickdns
     * @param null $name
     */
    public function __construct(QuickDns $quickdns, $name = null)
    {
        $this->quickdns = $quickdns;
        $this->name = $name;
    }

    /**
     * Create Group
     * @return bool
     */
    public function create()
    {
        $response = $this->quickdns->request('addgroup', [
            'group' => $this->name
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }
        return true;
    }

    /**
     * Delete Group
     * @return bool
     */
    public function delete()
    {
        if (!$this->id) {
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
}

<?php
namespace QuickDns;

/**
 * Class Zone
 * @property string $domain
 * @property array $templates
 * @property array $groups
 * @package QuickDns
 */
class Zone extends BaseModel
{
    protected $quickdns;

    public $domain;
    public $templates;
    public $groups;

    /**
     * Zone constructor.
     * @param QuickDns $quickdns
     * @param null $domain
     */
    public function __construct(QuickDns $quickdns, $domain = null)
    {
        $this->quickdns = $quickdns;
        $this->domain = $domain;
    }

    /**
     * Create Zone
     * @param bool $get_data
     * @return bool
     */
    public function create($get_data = false)
    {
        $response = $this->quickdns->request('addzone', [
            'zone' => $this->domain,
            'getdata' => $get_data ? 1 : 0,
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }
        return true;
    }

    /**
     * Delete Zone
     * @return bool
     */
    public function delete()
    {
        if (!$this->id) {
            throw new \BadFunctionCallException('Zone is not created yet.');
        }
        $response = $this->quickdns->request('delzone', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);
        if (strpos($response, 'ERROR')) {
            $xml = new \SimpleXMLElement($response);
            throw new \InvalidArgumentException($xml->statustext);
        }
        return true;
    }
}

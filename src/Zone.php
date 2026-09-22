<?php

namespace QuickDns;

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

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

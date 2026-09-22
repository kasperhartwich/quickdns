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
        $this->quickdns->command('addtemplate', [
            'zone' => $this->name,
        ], QuickDns::METHOD_GET);

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
     * Add Zone to template
     * TODO: Support multiple templates
     *
     * @return $this
     */
    public function addZone(Zone $zone)
    {
        $this->quickdns->command('updatetemplates', [
            'zone' => $zone->id,
            'template' => $this->id,
        ]);

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
        $this->quickdns->command('updatetemplates', [
            'zone' => $zone->id,
        ]);

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\MissingId;

/**
 * Class Group
 *
 * @property string $name
 * @property Member[] $members
 */
class Group extends BaseModel
{
    protected QuickDns $quickdns;

    public $name;

    /**
     * @var Member[]
     */
    public $members = [];

    /**
     * Group constructor.
     */
    public function __construct(QuickDns $quickdns, ?string $name = null)
    {
        $this->quickdns = $quickdns;
        $this->name = $name;
    }

    /**
     * Create Group. QuickDNS does not answer with the new group's id, so fetch the group with
     * QuickDns::getGroup() before deleting it or adding zones.
     */
    public function create(): static
    {
        $this->quickdns->command('addgroup', [
            'group' => $this->name,
        ], QuickDns::METHOD_GET);

        return $this;
    }

    /**
     * Delete Group
     */
    public function delete(): bool
    {
        if (! $this->id) {
            throw new MissingId('Group is not created yet.');
        }
        $this->quickdns->command('delgroup', [
            'id' => $this->id,
        ], QuickDns::METHOD_GET);

        return true;
    }

    /**
     * Rename the group.
     *
     */
    public function rename(string $name): static
    {
        $this->quickdns->command('renamegroup', [
            'group' => $this->id ?: throw new MissingId('Group is not created yet.'),
            'name' => $name,
        ]);
        $this->name = $name;

        return $this;
    }

    /**
     * Add a zone to the group, keeping the zone's other groups.
     */
    public function addZone(Zone $zone): static
    {
        $this->quickdns->setGroups($zone, array_merge($this->groupsOf($zone), [$this]));

        return $this;
    }

    /**
     * Take a zone off the group, leaving the zone's other groups alone.
     */
    public function removeZone(Zone $zone): static
    {
        $this->quickdns->setGroups($zone, array_values(array_filter(
            $this->groupsOf($zone),
            fn (int $id) => $id !== $this->id,
        )));

        return $this;
    }

    /**
     * The ids of the groups the zone has right now. The zones page carries them, so no lookup
     * is needed for a zone that came from there.
     *
     * @return int[]
     */
    private function groupsOf(Zone $zone): array
    {
        if ($zone->groupIds !== null) {
            return $zone->groupIds;
        }

        return $this->quickdns->getZone($zone->domain)->groupIds;
    }
}

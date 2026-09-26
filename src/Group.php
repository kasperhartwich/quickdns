<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\NotFound;

/**
 * A group on the account, as the groups page lists it, or one about to be created.
 */
final readonly class Group extends BaseModel
{
    /**
     * @param  Member[]  $members
     */
    public function __construct(
        private QuickDns $quickdns,
        public string $name,
        ?int $id = null,
        public array $members = [],
    ) {
        parent::__construct($id);
    }

    /**
     * Create the group on QuickDNS. QuickDNS does not answer with the new group's id, so the
     * groups page is read to find it.
     *
     * @return self The group with its id
     *
     * @throws NotFound when the new group is not on the groups page
     */
    public function create(): self
    {
        $this->quickdns->command('addgroup', [
            'group' => $this->name,
        ], QuickDns::METHOD_GET);

        return $this->quickdns->getGroup($this->name);
    }

    /**
     * Delete the group on QuickDNS.
     */
    public function delete(): void
    {
        $this->quickdns->command('delgroup', [
            'id' => $this->requireId(),
        ], QuickDns::METHOD_GET);
    }

    /**
     * Rename the group.
     *
     * @return self The group under its new name
     */
    public function rename(string $name): self
    {
        $this->quickdns->command('renamegroup', [
            'group' => $this->requireId(),
            'name' => $name,
        ]);

        return new self($this->quickdns, $name, $this->id, $this->members);
    }

    /**
     * Put a zone in the group, keeping the zone's other groups. The zone's current list is read
     * from QuickDNS first, since QuickDNS replaces the whole list.
     *
     * @return Zone The zone as QuickDNS shows it afterwards
     */
    public function addZone(Zone $zone): Zone
    {
        $id = $this->requireId();
        $current = $this->quickdns->getZone($zone->domain);
        if (in_array($id, $current->groupIds ?? [], true)) {
            return $current;
        }

        $ids = [...$current->groupIds ?? [], $id];
        $this->quickdns->setGroups($current, $ids);

        return $this->quickdns->getZone($current->domain);
    }

    /**
     * Take a zone out of the group, leaving the zone's other groups alone.
     *
     * @return Zone The zone as QuickDNS shows it afterwards
     */
    public function removeZone(Zone $zone): Zone
    {
        $id = $this->requireId();
        $current = $this->quickdns->getZone($zone->domain);
        if (! in_array($id, $current->groupIds ?? [], true)) {
            return $current;
        }

        $ids = array_values(array_filter($current->groupIds ?? [], fn (int $other) => $other !== $id));
        $this->quickdns->setGroups($current, $ids);

        return $this->quickdns->getZone($current->domain);
    }
}

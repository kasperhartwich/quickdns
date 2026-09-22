<?php

namespace QuickDns\Tests;

use QuickDns\Group;

final class GroupTest extends TestCase
{
    public function test_create_group_success()
    {
        $group = (new Group($this->quickDns, 'quickdns-api-group'))->create();
        $this->assertEquals(Group::class, get_class($group));
    }

    public function test_create_group_fail_already_exists()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gruppen eksisterer allerede');

        (new Group($this->quickDns, 'quickdns-api-group'))->create();
    }

    public function test_create_group_fail_illegal_templateName()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gruppens navn er ugyldigt');

        (new Group($this->quickDns, '@@'))->create();
    }

    public function test_get_groups()
    {
        $groups = $this->quickDns->getGroups();
        $this->assertSame('quickdns-api-group', array_shift($groups)->name);
    }

    public function test_delete_group_success()
    {
        $this->quickDns->getGroup('quickdns-api-group')->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown group');
        $this->quickDns->getGroup('quickdns-api-group');
    }
}

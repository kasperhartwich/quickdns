<?php
namespace Tests\Group;

use QuickDns\Group;
use Tests\TestCase;
use QuickDns\QuickDns;

class CreateGroupTest extends TestCase
{
    public function testCreateSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $group = new Group($quickDns, 'quickdns-api-group');
        $group->create();
        $this->assertEquals(Group::class, get_class($group));
    }

    public function testCreateAlreadyExists()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gruppen eksisterer allerede');

        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $temnplate = new Group($quickDns, 'quickdns-api-group');
        $temnplate->create();
    }

    public function testCreateIllegalTemplateName()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gruppens navn er ugyldigt');

        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $temnplate = new Group($quickDns, '@@');
        $temnplate->create();
    }
}


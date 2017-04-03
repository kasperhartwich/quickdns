<?php
namespace Tests\Group;

use Tests\TestCase;
use QuickDns\QuickDns;

class DeleteGroupTest extends TestCase
{
    public function testDeleteSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $group = $quickDns->getGroup('quickdns-api-group');
        $group->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown group');
        $quickDns->getGroup('quickdns-api-group');
    }
}

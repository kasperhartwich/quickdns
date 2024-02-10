<?php

namespace Tests\Group;

use QuickDns\QuickDns;
use Tests\TestCase;

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

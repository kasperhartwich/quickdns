<?php

namespace Tests\Template;

use QuickDns\QuickDns;
use Tests\TestCase;

class DeleteTemplateTest extends TestCase
{
    public function testDeleteSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $template = $quickDns->getTemplate('quickdns-api-template');
        $template->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown template');
        $quickDns->getTemplate('quickdns-api-template');
    }
}

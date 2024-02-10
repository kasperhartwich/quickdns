<?php

namespace Tests\Template;

use QuickDns\QuickDns;
use QuickDns\Template;
use Tests\TestCase;

class CreateTemplateTest extends TestCase
{
    public function testCreateSuccess()
    {
        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $template = new Template($quickDns, 'quickdns-api-template');
        $template->create();
        $this->assertEquals(Template::class, get_class($template));
    }

    public function testCreateAlreadyExists()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skabelonen eksisterer allerede');

        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $template = new Template($quickDns, 'quickdns-api-template');
        $template->create();
    }

    public function testCreateIllegalTemplateName()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skabelonens navn er ugyldigt');

        $quickDns = new QuickDns($this->apiEmail, $this->apiPassword);
        $template = new Template($quickDns, '@@');
        $template->create();
    }
}

<?php

namespace QuickDns\Tests;

use QuickDns\Template;

final class TemplateTest extends TestCase
{
    public function test_create_template_success()
    {
        $template = (new Template($this->quickDns, 'quickdns-api-template'))->create();
        $this->assertEquals(Template::class, get_class($template));
    }

    public function test_create_already_exists()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skabelonen eksisterer allerede');

        (new Template($this->quickDns, 'quickdns-api-template'))->create();
    }

    public function test_create_fail_illegal_template_name()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skabelonens navn er ugyldigt');

        (new Template($this->quickDns, '@@'))->create();
    }

    public function test_delete_template_success()
    {
        $this->quickDns->getTemplate('quickdns-api-template')->delete();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown template');
        $this->quickDns->getTemplate('quickdns-api-template');
    }
}

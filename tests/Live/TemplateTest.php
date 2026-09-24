<?php

declare(strict_types=1);

namespace QuickDns\Tests\Live;

use QuickDns\Template;
use QuickDns\Zone;

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

    /**
     * The 2.5 bug this release fixes: updatetemplates replaces the whole list, so adding or
     * removing one template dropped the others.
     */
    public function test_several_templates_on_one_zone()
    {
        $quickDns = $this->quickDns;
        $second = (new Template($quickDns, 'quickdns-api-second-template'))->create();
        $zone = (new Zone($quickDns, $this->testDomain))->create();

        try {
            $quickDns->getTemplate($this->testTemplate)->addZone($quickDns->getZone($this->testDomain));
            $second->addZone($quickDns->getZone($this->testDomain));

            $both = $quickDns->getZone($this->testDomain);
            // QuickDNS lists a zone's templates alphabetically, not in the order they were added.
            $this->assertSame(['quickdns-api-second-template', $this->testTemplate], $both->templates);
            $this->assertCount(2, $both->templateIds);

            // Taking one off must leave the other.
            $second->removeZone($both);
            $this->assertSame([$this->testTemplate], $quickDns->getZone($this->testDomain)->templates);

            // And setting the list outright.
            $quickDns->setTemplates($quickDns->getZone($this->testDomain), ['quickdns-api-second-template']);
            $this->assertSame(['quickdns-api-second-template'], $quickDns->getZone($this->testDomain)->templates);

            $quickDns->setTemplates($quickDns->getZone($this->testDomain), []);
            $this->assertSame([], $quickDns->getZone($this->testDomain)->templates);
        } finally {
            $quickDns->getZone($this->testDomain)->delete();
            $second->delete();
        }
    }
}

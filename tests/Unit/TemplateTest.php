<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;
use QuickDns\Template;
use QuickDns\Zone;

final class TemplateTest extends TestCase
{
    public function test_create()
    {
        $template = (new Template($this->quickDns(['addtemplate-ok']), 'sjaskende-rabarber'))->create();

        $this->assertInstanceOf(Template::class, $template);
        $this->assertSame('addtemplate?zone=sjaskende-rabarber', $this->lastRequestUri());
    }

    public function test_create_already_exists()
    {
        $template = new Template($this->quickDns(['addtemplate-exists']), 'sjaskende-rabarber');

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Skabelonen eksisterer allerede');
        $template->create();
    }

    public function test_delete()
    {
        $template = new Template($this->quickDns(['deltemplate']), 'sjaskende-rabarber');
        $template->id = 17295;

        $this->assertTrue($template->delete());
        $this->assertSame('deltemplate?id=17295', $this->lastRequestUri());
    }

    public function test_add_zone()
    {
        $quickDns = $this->quickDns(['updatetemplates']);
        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;

        $this->assertSame($template, $template->addZone($zone));
        $this->assertSame('updatetemplates?zone=17296&template=17295', $this->lastRequestUri());
    }

    public function test_add_unknown_zone()
    {
        $quickDns = $this->quickDns(['updatetemplates-error']);
        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $zone = new Zone($quickDns, 'findes-ikke.dk');
        $zone->id = 999999999;

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Zonen findes ikke');
        $template->addZone($zone);
    }

    public function test_remove_zone()
    {
        $quickDns = $this->quickDns(['updatetemplates']);
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;

        (new Template($quickDns, 'sjaskende-rabarber'))->removeZone($zone);

        $this->assertSame('updatetemplates?zone=17296', $this->lastRequestUri());
    }
}

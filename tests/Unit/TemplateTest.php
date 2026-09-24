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
        $this->assertSame(17295, $template->id);
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

    public function test_add_zone_keeps_the_zones_other_templates()
    {
        $quickDns = $this->quickDns(['updatetemplates']);
        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;
        $zone->templateIds = [17280];

        $this->assertSame($template, $template->addZone($zone));
        // The other template is sent along, or QuickDNS would drop it.
        $this->assertSame('updatetemplates?zone=17296&template=17280&template=17295', urldecode($this->lastRequestUri()));
    }

    public function test_set_templates_by_name()
    {
        $quickDns = $this->quickDns(['templates', 'updatetemplates']);
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;

        $quickDns->setTemplates($zone, ['test-template']);

        // Names are resolved to ids, which is what QuickDNS wants.
        $this->assertSame('updatetemplates?zone=17296&template=17284', urldecode($this->lastRequestUri()));
    }

    public function test_set_templates_to_none()
    {
        $quickDns = $this->quickDns(['updatetemplates']);

        $quickDns->setTemplates(17296, []);

        $this->assertSame('updatetemplates?zone=17296', urldecode($this->lastRequestUri()));
    }

    public function test_set_templates_with_an_unknown_name()
    {
        $quickDns = $this->quickDns(['templates']);

        $this->expectException(\QuickDns\Exceptions\NotFound::class);
        $quickDns->setTemplates(17296, ['findes-ikke']);
    }

    public function test_add_unknown_zone()
    {
        $quickDns = $this->quickDns(['updatetemplates-error']);
        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $zone = new Zone($quickDns, 'findes-ikke.dk');
        $zone->id = 999999999;
        $zone->templateIds = [];   // known to have none, so no lookup

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Zonen findes ikke');
        $template->addZone($zone);
    }

    public function test_remove_zone_leaves_the_others_alone()
    {
        $quickDns = $this->quickDns(['updatetemplates']);
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;
        $zone->templateIds = [17295, 17280];

        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $template->removeZone($zone);

        $this->assertSame('updatetemplates?zone=17296&template=17280', urldecode($this->lastRequestUri()));
    }

    public function test_remove_the_last_template()
    {
        $quickDns = $this->quickDns(['updatetemplates']);
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17296;
        $zone->templateIds = [17295];

        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $template->removeZone($zone);

        $this->assertSame('updatetemplates?zone=17296', urldecode($this->lastRequestUri()));
    }

    public function test_a_zone_without_a_template_list_is_looked_up()
    {
        $quickDns = $this->quickDns(['zones', 'updatetemplates']);
        $template = new Template($quickDns, 'sjaskende-rabarber');
        $template->id = 17295;
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk');
        $zone->id = 17287;

        $template->addZone($zone);

        // The recorded zones page carries test-template's id in the row's onclick.
        $this->assertSame('updatetemplates?zone=17287&template=17284&template=17295', urldecode($this->lastRequestUri()));
    }
}

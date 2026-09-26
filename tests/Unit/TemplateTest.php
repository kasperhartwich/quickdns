<?php

declare(strict_types=1);

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
        $template = new Template($this->quickDns(['deltemplate']), 'sjaskende-rabarber', 17295);

        $template->delete();
        $this->assertSame('deltemplate?id=17295', $this->lastRequestUri());
    }

    public function test_add_zone_keeps_the_zones_other_templates()
    {
        $quickDns = $this->quickDns([$this->zonesPage([17280]), 'updatetemplates', $this->zonesPage([17280, 17295])]);
        $template = new Template($quickDns, 'sjaskende-rabarber', 17295);

        $zone = $template->addZone(new Zone($quickDns, 'flyvende-agurk-pingvin.dk'));

        // The other template is sent along, or QuickDNS would drop it.
        $this->assertSame('updatetemplates?zone=17287&template=17280&template=17295', urldecode($this->requestUri(2)));
        // The zone as QuickDNS shows it afterwards: read again.
        $this->assertSame('zones', $this->lastRequestUri());
        $this->assertSame([17280, 17295], $zone->templateIds);
    }

    public function test_the_zones_list_is_read_fresh_every_time()
    {
        // The Zone passed in claims no templates; QuickDNS knows better, and QuickDNS wins.
        $quickDns = $this->quickDns([$this->zonesPage([17280]), 'updatetemplates', 'zones', $this->zonesPage([17280, 17295]), 'updatetemplates', 'zones']);
        $stale = new Zone($quickDns, 'flyvende-agurk-pingvin.dk', 17287, templateIds: []);

        (new Template($quickDns, 'sjaskende-rabarber', 17295))->addZone($stale);
        (new Template($quickDns, 'another', 17300))->addZone($stale);

        $this->assertSame('updatetemplates?zone=17287&template=17280&template=17295&template=17300', urldecode($this->requestUri(5)));
    }

    public function test_adding_a_template_the_zone_has_sends_nothing()
    {
        $quickDns = $this->quickDns([$this->zonesPage([17295])]);

        (new Template($quickDns, 'sjaskende-rabarber', 17295))->addZone(new Zone($quickDns, 'flyvende-agurk-pingvin.dk'));

        $this->assertSame('zones', $this->lastRequestUri());
    }

    public function test_set_templates_by_name()
    {
        $quickDns = $this->quickDns(['templates', 'updatetemplates']);
        $zone = new Zone($quickDns, 'flyvende-agurk-pingvin.dk', 17296);

        $quickDns->setTemplates($zone, ['test-template']);

        // Names are resolved to ids, which is what QuickDNS wants.
        $this->assertSame('updatetemplates?zone=17296&template=17284', urldecode($this->lastRequestUri()));
    }

    public function test_a_numeric_string_is_a_name_not_an_id()
    {
        $fake = new \QuickDns\Testing\FakeQuickDns();
        $id = $fake->addTemplate('2024');
        $fake->addZone('example.dk');
        $quickDns = $fake->quickDns();

        $quickDns->setTemplates($quickDns->getZone('example.dk'), ['2024']);

        $this->assertSame(['2024'], $fake->templatesOf('example.dk'));
        $this->assertNotSame(2024, $id);
    }

    public function test_an_id_of_zero_is_refused_before_any_request()
    {
        $quickDns = $this->quickDns();

        $this->expectException(\QuickDns\Exceptions\MissingId::class);
        $this->expectExceptionMessage('Not a zone id: 0');
        $quickDns->setTemplates(0, []);
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
        $quickDns = $this->quickDns(['zones']);
        $template = new Template($quickDns, 'sjaskende-rabarber', 17295);

        $this->expectException(\QuickDns\Exceptions\NotFound::class);
        $template->addZone(new Zone($quickDns, 'findes-ikke.dk', 999999999));
    }

    public function test_remove_zone_leaves_the_others_alone()
    {
        $quickDns = $this->quickDns([$this->zonesPage([17295, 17280]), 'updatetemplates', $this->zonesPage([17280])]);

        $zone = (new Template($quickDns, 'sjaskende-rabarber', 17295))->removeZone(new Zone($quickDns, 'flyvende-agurk-pingvin.dk'));

        $this->assertSame('updatetemplates?zone=17287&template=17280', urldecode($this->requestUri(2)));
        $this->assertSame([17280], $zone->templateIds);
    }

    public function test_remove_the_last_template()
    {
        $quickDns = $this->quickDns([$this->zonesPage([17295]), 'updatetemplates', $this->zonesPage([])]);

        (new Template($quickDns, 'sjaskende-rabarber', 17295))->removeZone(new Zone($quickDns, 'flyvende-agurk-pingvin.dk'));

        $this->assertSame('updatetemplates?zone=17287', urldecode($this->requestUri(2)));
    }

    public function test_a_template_without_an_id_cannot_be_added()
    {
        $quickDns = $this->quickDns();

        $this->expectException(\QuickDns\Exceptions\MissingId::class);
        (new Template($quickDns, 'sjaskende-rabarber'))->addZone(new Zone($quickDns, 'flyvende-agurk-pingvin.dk'));
    }
}

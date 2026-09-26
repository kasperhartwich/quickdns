<?php

declare(strict_types=1);

namespace QuickDns\Tests\Live;

use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
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
        $this->expectException(\QuickDns\Exceptions\CommandFailed::class);
        $this->expectExceptionMessage('Skabelonen eksisterer allerede');

        (new Template($this->quickDns, 'quickdns-api-template'))->create();
    }

    public function test_create_fail_illegal_template_name()
    {
        $this->expectException(\QuickDns\Exceptions\CommandFailed::class);
        $this->expectExceptionMessage('Skabelonens navn er ugyldigt');

        (new Template($this->quickDns, '@@'))->create();
    }

    public function test_delete_template_success()
    {
        $this->quickDns->getTemplate('quickdns-api-template')->delete();

        $this->expectException(\QuickDns\Exceptions\NotFound::class);
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

    public function test_records_on_a_template()
    {
        $template = (new Template($this->quickDns, 'quickdns-api-records-template'))->create();

        try {
            $this->assertSame([], $template->getRecords(), 'A new template has no records.');

            $template->edit(function (RecordSet $records) {
                $records->add('www', 'A', '192.0.2.10', ttl: 3600);
                $records->add('@', RecordType::MX, 'mx1.example.dk.', ttl: 3600, priority: 10);
            });

            $summary = array_map(fn (Record $r) => "{$r->name} {$r->type} {$r->priority} {$r->value}", $template->getRecords());
            $this->assertContains('www A  192.0.2.10', $summary);
            $this->assertContains('@ MX 10 mx1.example.dk.', $summary);

            // A zone using the template gets them, marked as the template's.
            $zone = (new Zone($this->quickDns, $this->testDomain))->create();
            $template->addZone($this->quickDns->getZone($this->testDomain));
            $fromTemplate = array_values(array_filter(
                $this->quickDns->getZone($this->testDomain)->getRecords(),
                fn (Record $r) => $r->template === 'quickdns-api-records-template',
            ));
            $this->assertCount(2, $fromTemplate);
            $this->assertTrue($fromTemplate[0]->isLocked());

            $this->quickDns->getZone($this->testDomain)->delete();

            $template->deleteRecord($template->getRecords()[0]);
            $this->assertCount(1, $template->getRecords());
        } finally {
            foreach ($this->quickDns->getZones() as $zone) {
                if ($zone->domain === $this->testDomain) {
                    $zone->delete();
                }
            }
            $template->delete();
        }
    }

    public function test_rename_a_template_and_a_group()
    {
        $template = (new Template($this->quickDns, 'quickdns-api-rename-me'))->create();

        $group = $this->quickDns->getGroup($this->testGroup);

        try {
            $renamed = $template->rename('quickdns-api-renamed');

            $this->assertSame('quickdns-api-renamed', $renamed->name);
            $this->assertSame($template->id, $this->quickDns->getTemplate('quickdns-api-renamed')->id);

            $group->rename('quickdns-api-renamed-group');
            $this->assertSame($group->id, $this->quickDns->getGroup('quickdns-api-renamed-group')->id);
        } finally {
            // The account is shared by every test in the suite, so the group goes back whatever
            // happens above.
            $group->rename($this->testGroup);
            $template->delete();
        }
    }
}

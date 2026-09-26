<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;
use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
use QuickDns\Template;
use QuickDns\Testing\FakeQuickDns;

/**
 * A template holds records like a zone, and every zone using it gets them. The edit protocol is
 * the same, except that it is saved with edittemplatedone.
 */
final class TemplateRecordsTest extends TestCase
{
    public function test_reading_a_templates_records()
    {
        $template = new Template($this->quickDns(['editzone']), 'test-template', 17284);

        $records = $template->getRecords();

        $this->assertSame('edittemplate?id=17284', $this->lastRequestUri());
        $this->assertCount(11, $records);
    }

    public function test_the_session_is_saved_as_a_template()
    {
        $template = new Template($this->quickDns(['editzone', 'submitzonechange-initial', 'submitzonechange-insert', 'editzonedone-saved']), 'test-template', 17284);

        $template->edit(fn (RecordSet $records) => $records->add('zulu', 'A', '192.0.2.26', ttl: 3600));

        $uris = array_map(fn ($entry) => (string) $entry['request']->getUri(), $this->history);
        $this->assertStringContainsString('edittemplate?id=17284', $uris[1]);
        // editzonedone on a template saves nothing at all, so the endpoint has to follow.
        $this->assertStringContainsString('edittemplatedone?save=1', end($uris));
    }

    public function test_an_exception_discards_the_template_session()
    {
        $template = new Template($this->quickDns(['editzone', 'submitzonechange-initial', 'submitzonechange-insert', 'editzonedone-discarded']), 'test-template', 17284);

        try {
            $template->edit(function (RecordSet $records) {
                $records->add('zulu', 'A', '192.0.2.26', ttl: 3600);
                throw new \RuntimeException('no');
            });
            $this->fail('Expected the closure to throw.');
        } catch (\RuntimeException) {
        }

        $uris = array_map(fn ($entry) => (string) $entry['request']->getUri(), $this->history);
        $this->assertStringContainsString('edittemplatedone?save=0', end($uris));
    }

    public function test_a_template_without_an_id()
    {
        $template = new Template($this->quickDns(), 'test-template');

        $this->expectException(\QuickDns\Exceptions\MissingId::class);
        $this->expectExceptionMessage('Template is not created yet.');
        $template->getRecords();
    }

    public function test_the_whole_round_trip_against_the_fake()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('standard');
        $fake->addTemplateRecord('standard', '@', 'MX', 'mx1.example.dk.', 3600, 10);
        $quickDns = $fake->quickDns();
        $template = $quickDns->getTemplate('standard');

        $template->edit(function (RecordSet $records) {
            $records->add('www', 'A', '192.0.2.10', ttl: 3600);
            $mx = $records->sole(name: '@', type: RecordType::MX);
            $records->replace($mx, $mx->withValue('mx2.example.dk.'));
        });

        $this->assertSame(
            ['@ MX mx2.example.dk.', 'www A 192.0.2.10'],
            array_map(fn (Record $r) => "{$r->name} {$r->type} {$r->value}", $template->getRecords()),
        );
        // A zone using the template gets them, as the template's and locked.
        $fake->addZone('example.dk', ['standard']);
        $onTheZone = $quickDns->getZone('example.dk')->getRecords();
        $fromTemplate = array_values(array_filter($onTheZone, fn (Record $r) => $r->template === 'standard'));

        $this->assertCount(2, $fromTemplate);
        $this->assertTrue($fromTemplate[0]->isLocked());
        $this->assertCount(6, $onTheZone, 'Four NS records of its own, plus the template\'s two.');
    }

    public function test_rename_a_template()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('standard');
        $quickDns = $fake->quickDns();
        $template = $quickDns->getTemplate('standard');

        $renamed = $template->rename('fancy');

        $this->assertSame('fancy', $renamed->name);
        $this->assertSame($template->id, $renamed->id);
        $this->assertSame('standard', $template->name, 'The old object keeps describing what it was read as.');
        $this->assertTrue($fake->hasTemplate('fancy'));
        $this->assertFalse($fake->hasTemplate('standard'));
    }

    public function test_rename_a_group()
    {
        $fake = new FakeQuickDns();
        $fake->addGroup('kunder');
        $quickDns = $fake->quickDns();

        $quickDns->getGroup('kunder')->rename('clients');

        $this->assertTrue($fake->hasGroup('clients'));
    }

    public function test_renaming_to_an_invalid_name()
    {
        $fake = new FakeQuickDns();
        $fake->addTemplate('standard');
        $template = $fake->quickDns()->getTemplate('standard');

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Skabelonens navn er ugyldigt');
        $template->rename('@@');
    }

    public function test_renaming_something_that_is_not_created_yet()
    {
        $template = new Template($this->quickDns(), 'standard');

        $this->expectException(\QuickDns\Exceptions\MissingId::class);
        $template->rename('fancy');
    }
}

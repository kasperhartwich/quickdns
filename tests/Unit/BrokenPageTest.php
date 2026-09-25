<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\UnrecognisedPage;

/**
 * QuickDNS has no API, so every read is a scrape of a page that can change. A page this library
 * does not recognise must say so, rather than hand back a zone with id 0 or a template with no
 * name.
 *
 * The pages here are the recorded ones with one thing broken.
 */
final class BrokenPageTest extends TestCase
{
    public function test_a_zone_row_without_an_id()
    {
        $page = str_replace('zoneid="17287"', 'zoneid=""', $this->fixture('zones'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('Unexpected row on the zones page');
        $this->quickDns([$page])->getZones();
    }

    public function test_a_zone_row_whose_id_is_not_a_number()
    {
        $page = str_replace('zoneid="17287"', 'zoneid="new"', $this->fixture('zones'));

        $this->expectException(UnrecognisedPage::class);
        $this->quickDns([$page])->getZones();
    }

    public function test_a_zone_row_with_a_column_missing()
    {
        $page = str_replace('<td class="listrow2">2026-09-22 18:21:28</td>', '', $this->fixture('zones'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('Unexpected row on the zones page');
        $this->quickDns([$page])->getZones();
    }

    public function test_a_template_row_without_a_link()
    {
        $page = str_replace('<a href="/edittemplate?id=17284">test-template</a>', 'test-template', $this->fixture('templates'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('has no href');
        $this->quickDns([$page])->getTemplates();
    }

    public function test_a_template_link_without_an_id()
    {
        $page = str_replace('/edittemplate?id=17284', '/edittemplate', $this->fixture('templates'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('No id in');
        $this->quickDns([$page])->getTemplates();
    }

    public function test_a_template_link_whose_id_runs_into_something_else()
    {
        // The id has to be the whole value, or "id=17284broken" would read as template 17284.
        $page = str_replace('/edittemplate?id=17284', '/edittemplate?id=17284broken', $this->fixture('templates'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('No id in');
        $this->quickDns([$page])->getTemplates();
    }

    public function test_a_template_link_with_more_parameters_still_reads()
    {
        $page = str_replace('/edittemplate?id=17284', '/edittemplate?id=17284&back=1', $this->fixture('templates'));

        $this->assertSame(17284, $this->quickDns([$page])->getTemplates()[0]->id);
    }

    public function test_a_group_row_without_its_handler()
    {
        $page = str_replace('onclick="groupid = 738; edit_members(parentNode.parentNode.rowIndex);"', '', $this->fixture('groups'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('has no onclick');
        $this->quickDns([$page])->getGroups();
    }

    public function test_a_group_handler_without_an_id()
    {
        $page = str_replace('groupid = 738;', 'groupid = nothing;', $this->fixture('groups'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('No id in');
        $this->quickDns([$page])->getGroups();
    }

    public function test_a_template_row_with_a_column_missing()
    {
        $page = str_replace('<td class="listrow2">2026-09-22 18:10:09</td>', '', $this->fixture('templates'));

        $this->expectException(UnrecognisedPage::class);
        $this->expectExceptionMessage('has 5 cells, not 6');
        $this->quickDns([$page])->getTemplates();
    }

    /**
     * The recorded pages still read as they did, so the guards did not narrow what counts as a
     * page this library understands.
     */
    public function test_the_real_pages_still_read()
    {
        $this->assertCount(1, $this->quickDns(['zones'])->getZones());
        $this->assertCount(1, $this->quickDns(['templates'])->getTemplates());
        $this->assertCount(1, $this->quickDns(['groups'])->getGroups());
        $this->assertSame([], $this->quickDns(['zones-empty'])->getZones());
    }
}

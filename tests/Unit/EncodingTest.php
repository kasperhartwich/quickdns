<?php

namespace QuickDns\Tests\Unit;

use QuickDns\Exceptions\CommandFailed;

/**
 * QuickDNS sends ISO-8859-1. Everything the library returns must be UTF-8.
 */
final class EncodingTest extends TestCase
{
    public function test_command_statustext_with_danish_letters()
    {
        $xml = mb_convert_encoding('<?xml version="1.0" encoding="ISO-8859-1"?><response><status>ERROR</status><statustext>Zonen må ikke ændres</statustext></response>', 'ISO-8859-1', 'UTF-8');

        $this->expectException(CommandFailed::class);
        $this->expectExceptionMessage('Zonen må ikke ændres');
        $this->quickDns([$xml])->command('delzone', ['id' => 1]);
    }

    public function test_command_with_lowercase_declaration()
    {
        $xml = mb_convert_encoding('<?xml version="1.0" encoding="iso-8859-1"?><response><status>OK</status><statustext>Skabelonen er ændret</statustext></response>', 'ISO-8859-1', 'UTF-8');

        $response = $this->quickDns([$xml])->command('updatetemplates', ['zone' => 1]);

        $this->assertSame('Skabelonen er ændret', $response->filterXPath('//response/statustext')->text());
    }

    public function test_list_page_with_danish_letters()
    {
        $page = str_replace('test-template', mb_convert_encoding('rødgrød-med-fløde', 'ISO-8859-1', 'UTF-8'), $this->fixture('templates'));

        $templates = $this->quickDns([$page])->getTemplates();

        $this->assertSame('rødgrød-med-fløde', $templates[0]->name);
    }
}

<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use QuickDns\QuickDns;
use QuickDns\Zone;

/**
 * Before the client could be injected, the way to mock QuickDns was a subclass overriding
 * request(). Every request must still go through it.
 */
final class SubclassTest extends TestCase
{
    public function test_every_request_goes_through_an_overridden_request()
    {
        $quickDns = new class('test@example.dk', 'secret') extends QuickDns
        {
            public array $calls = [];

            public function request($function, $options = [], $method = self::METHOD_GET): string
            {
                $this->calls[] = $function;
                $fixtures = ['login' => 'login-ok', 'zones' => 'zones', 'addzone' => 'addzone-ok', 'delzone' => 'delzone'];

                $body = file_get_contents(__DIR__.'/../Fixtures/'.$fixtures[$function].'.html');

                // What request() itself returns: the page without its lowercase declaration.
                return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', $body);
            }
        };

        (new Zone($quickDns, 'flyvende-agurk-pingvin.dk'))->create();
        $quickDns->getZone('flyvende-agurk-pingvin.dk')->delete();

        $this->assertSame(['login', 'addzone', 'zones', 'delzone'], $quickDns->calls);
    }
}

<?php
namespace Tests;

/**
 * Class TestCase
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    protected $quickDns;
    protected $apiEmail;
    protected $apiPassword;

    protected function setUp(): void
    {
        if (!getenv('QUICKDNS_EMAIL')) {
            throw new \Exception('ENV variables is not set. See documentation,.');
        }
        $this->apiEmail = getenv('QUICKDNS_EMAIL');
        $this->apiPassword= getenv('QUICKDNS_PASSWORD');
        parent::setUp();
    }
}

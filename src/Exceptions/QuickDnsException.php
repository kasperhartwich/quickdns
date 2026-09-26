<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

/**
 * The parent of every exception this library throws for QuickDNS itself, so one catch covers
 * them all.
 */
abstract class QuickDnsException extends \Exception
{
}

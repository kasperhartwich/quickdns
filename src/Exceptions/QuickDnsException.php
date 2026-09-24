<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

/**
 * Implemented by every exception this library throws for QuickDNS itself, so one catch covers
 * them all.
 */
interface QuickDnsException extends \Throwable
{
}

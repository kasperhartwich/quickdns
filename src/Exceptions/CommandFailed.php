<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

/**
 * QuickDNS answered a command (addzone, delzone, ...) with ERROR. The message is QuickDNS' own
 * statustext, in Danish, e.g. "Zonen eksisterer allerede".
 */
class CommandFailed extends \InvalidArgumentException implements QuickDnsException
{
}

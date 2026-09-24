<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

/**
 * No zone, template or group with the given domain or name exists on the account.
 */
class NotFound extends \UnexpectedValueException implements QuickDnsException
{
}

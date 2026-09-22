<?php

namespace QuickDns\Exceptions;

/**
 * QuickDNS rejected the email or password.
 */
class LoginFailed extends \InvalidArgumentException implements QuickDnsException
{
}

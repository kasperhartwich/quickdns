<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

/**
 * The zone, template or group has no id yet: create() it, or fetch it from QuickDNS, first.
 */
class MissingId extends QuickDnsException
{
}

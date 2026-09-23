<?php

namespace QuickDns\Exceptions;

/**
 * The record cannot be sent to QuickDNS as it is: an unknown type, a priority on a type that has
 * none, an empty or duplicate record, or a character QuickDNS rejects. Thrown before any request.
 */
class InvalidRecord extends \InvalidArgumentException implements QuickDnsException
{
}

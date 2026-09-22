<?php

namespace QuickDns\Exceptions;

/**
 * QuickDNS answered with something this library does not recognise: not logged in, a changed
 * page layout, or a response that is not the expected XML.
 */
class UnrecognisedPage extends \UnexpectedValueException implements QuickDnsException
{
}

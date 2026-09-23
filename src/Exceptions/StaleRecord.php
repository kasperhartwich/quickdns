<?php

namespace QuickDns\Exceptions;

/**
 * The record is no longer part of the set being edited, because it was replaced or removed
 * earlier in the same session. Use the record returned by replace(), or find it again.
 */
class StaleRecord extends \InvalidArgumentException implements QuickDnsException
{
}

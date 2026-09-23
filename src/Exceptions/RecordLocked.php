<?php

namespace QuickDns\Exceptions;

/**
 * The record belongs to a template, so it cannot be changed or deleted on the zone. QuickDNS
 * answers an HTTP 500 if asked anyway.
 */
class RecordLocked extends InvalidRecord
{
}

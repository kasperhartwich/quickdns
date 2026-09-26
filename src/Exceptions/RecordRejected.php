<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

use QuickDns\Record;

/**
 * QuickDNS rejected a change. The whole edit session is discarded: QuickDNS saves nothing at all
 * from a session that contains a rejected record, not even the changes it accepted.
 */
class RecordRejected extends CommandFailed
{
    /**
     * @param  string[]  $errors  QuickDNS' own messages, in Danish
     * @param  int[]  $rows  The rows QuickDNS marked as bad
     * @param  Record[]  $records  The records in those rows
     */
    public function __construct(
        string $message,
        string $status = '',
        private readonly array $errors = [],
        private readonly array $rows = [],
        private readonly array $records = [],
        private readonly ?Record $attempted = null,
    ) {
        // Here status() is QuickDNS' status line, e.g. "Status: 1 fejl i zonen".
        parent::__construct($message, 'submitzonechange', $status);
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return int[]
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return Record[]
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * The record being added or changed when QuickDNS said no.
     */
    public function attempted(): ?Record
    {
        return $this->attempted;
    }
}

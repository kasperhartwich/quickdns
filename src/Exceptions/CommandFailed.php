<?php

declare(strict_types=1);

namespace QuickDns\Exceptions;

/**
 * QuickDNS answered a command (addzone, delzone, ...) with ERROR. The message is QuickDNS' own
 * statustext, in Danish, e.g. "Zonen eksisterer allerede".
 */
class CommandFailed extends QuickDnsException
{
    /**
     * @param  string  $function  The command QuickDNS answered, e.g. "addzone"
     * @param  string  $status  QuickDNS' status, e.g. "ERROR"
     * @param  array<string, string>  $fields  The answer's other fields, e.g. ['zoneid' => '123']
     */
    public function __construct(
        string $message = '',
        private readonly string $function = '',
        private readonly string $status = '',
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /**
     * QuickDNS' own explanation, in Danish. The same as the message.
     */
    public function statusText(): string
    {
        return $this->getMessage();
    }

    /**
     * The command that failed, e.g. "addzone".
     */
    public function function(): string
    {
        return $this->function;
    }

    /**
     * QuickDNS' status, e.g. "ERROR".
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * The answer's fields besides status and statustext.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}

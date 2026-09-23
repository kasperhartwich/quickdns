<?php

namespace QuickDns\Internal;

use Symfony\Component\DomCrawler\Crawler;

/**
 * One answer from submitzonechange.
 *
 * @internal
 */
final class ZoneChangeResponse
{
    /**
     * @param  string[]  $errors
     * @param  int[]  $badRows
     * @param  ZoneAction[]  $actions
     */
    private function __construct(
        public readonly string $status,
        public readonly array $errors,
        public readonly array $badRows,
        public readonly array $actions,
    ) {
    }

    public static function fromXml(Crawler $xml): self
    {
        $status = $xml->filterXPath('//response/status');
        $errors = $xml->filterXPath('//response/error')->each(fn (Crawler $error) => self::text($error->text()));
        $badRows = $xml->filterXPath('//response/badrecord')->each(fn (Crawler $row) => (int) trim($row->text()));
        $actions = $xml->filterXPath('//response/actions')->each(fn (Crawler $action) => ZoneAction::fromXml($action));

        return new self($status->count() ? trim($status->text()) : '', $errors, $badRows, array_filter($actions));
    }

    /**
     * QuickDNS saves nothing at all from a session that holds a rejected record, so any error at
     * all means the session is spent.
     */
    public function rejected(): bool
    {
        return $this->errors !== [] || $this->badRows !== [];
    }

    /**
     * QuickDNS' error text is HTML inside XML: entities twice over, and a trailing <br>.
     */
    private static function text(string $error): string
    {
        return trim(strip_tags(html_entity_decode(html_entity_decode($error, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}

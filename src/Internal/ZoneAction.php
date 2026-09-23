<?php

namespace QuickDns\Internal;

use QuickDns\Record;
use Symfony\Component\DomCrawler\Crawler;

/**
 * One <actions> block from submitzonechange: what QuickDNS did to the table.
 *
 * @internal
 */
final class ZoneAction
{
    public const INSERT = 'insertrow';

    public const CHANGE = 'changerow';

    public const DELETE = 'deleterow';

    private function __construct(
        public readonly string $action,
        public readonly int $row,
        public readonly ?Record $record = null,
    ) {
    }

    public static function fromXml(Crawler $action): ?self
    {
        $field = function (string $name) use ($action): ?string {
            $node = $action->filterXPath('.//'.$name);

            return $node->count() ? html_entity_decode(trim($node->text()), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        };

        $what = $field('action');
        $row = $field('row');
        if ($what === null || $row === null) {
            return null;
        }

        $record = null;
        if ($what === self::CHANGE) {
            // A blank cell comes back as a non-breaking space.
            $blank = fn (?string $value) => $value === null || trim($value) === '' || trim($value) === "\u{00a0}";
            $record = new Record(
                (string) $field('record'),
                (string) $field('type'),
                $blank($field('ttl')) ? null : (int) $field('ttl'),
                $blank($field('priority')) ? null : (int) $field('priority'),
                (string) $field('value'),
                (int) $row,
            );
        }

        return new self($what, (int) $row, $record);
    }
}

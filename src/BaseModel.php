<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\UnrecognisedPage;

/**
 * Class BaseModel
 *
 * @property int $id
 */
class BaseModel
{
    /**
     * QuickDNS shows its times without a zone, in Danish local time.
     */
    public const TIMEZONE = 'Europe/Copenhagen';

    public $id;

    /**
     * A time as the list pages show it, "2026-09-22 18:21:28". An empty cell or "-" is no time;
     * anything else that does not parse means the page changed.
     *
     * @throws UnrecognisedPage
     */
    public static function parseUpdated(string $text): ?\DateTimeImmutable
    {
        $text = trim($text);
        if ($text === '' || $text === '-') {
            return null;
        }
        // The shape first: createFromFormat() throws a ValueError on some input, a NUL for one.
        // The hour the clocks go back happens twice, and the page does not say which one it was.
        $time = preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $text)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new \DateTimeZone(self::TIMEZONE))
            : false;
        if ($time === false || $time->format('Y-m-d H:i:s') !== $text) {
            throw new UnrecognisedPage('Not a time QuickDNS shows: '.$text);
        }

        return $time;
    }
}

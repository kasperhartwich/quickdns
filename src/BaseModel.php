<?php

declare(strict_types=1);

namespace QuickDns;

use QuickDns\Exceptions\MissingId;
use QuickDns\Exceptions\UnrecognisedPage;

/**
 * What zones, templates and groups share: QuickDNS' id, null until the thing exists there.
 *
 * The models are immutable. A method that changes something on QuickDNS returns the new state as a
 * new object, and the old one keeps describing what it was read as.
 */
abstract readonly class BaseModel
{
    /**
     * QuickDNS shows its times without a zone, in Danish local time.
     */
    public const TIMEZONE = 'Europe/Copenhagen';

    /**
     * Properties 2.x had and 3.0 does not, with what to use instead, per class.
     *
     * @var array<class-string, array<string, string>>
     */
    private const REMOVED = [
        Group::class => [
            'updated' => 'QuickDNS shows no time for a group',
        ],
    ];

    public function __construct(public ?int $id = null)
    {
    }

    /**
     * The id, for a call that needs one.
     *
     * @throws MissingId when the zone, template or group has none yet
     */
    public function requireId(): int
    {
        return $this->id ?? throw new MissingId((new \ReflectionClass($this))->getShortName().' is not created yet.');
    }

    /**
     * Reading a property 3.0 removed would otherwise give null and a warning, which reads as "not
     * set" rather than "gone". Say what to use instead.
     *
     * @throws \LogicException always
     */
    public function __get(string $name): never
    {
        $class = (new \ReflectionClass($this))->getShortName();
        foreach (self::REMOVED as $removedFrom => $properties) {
            if ($this instanceof $removedFrom && isset($properties[$name])) {
                throw new \LogicException($class.'::$'.$name.' was removed in 3.0: '.$properties[$name].'. See UPGRADING.md.');
            }
        }

        throw new \LogicException('Undefined property '.$class.'::$'.$name);
    }

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

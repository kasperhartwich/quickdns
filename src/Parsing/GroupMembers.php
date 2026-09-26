<?php

declare(strict_types=1);

namespace QuickDns\Parsing;

use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Member;

/**
 * The members of every group, from the groups page's inline script:
 *
 *     function init_groupmembers () {
 *       groupmembers = {738: [[12, 'Name &lt;name@example.dk&gt;', true]], 739: []};
 *     }
 *
 * The list cell only says how many there are ("2 medlemmer"), so the script is the one place
 * the page names them. QuickDNS' own groups.js reads each entry as [id, "name <email>" escaped
 * for HTML, confirmed].
 *
 * @internal
 */
final class GroupMembers
{
    private int $at = 0;

    private string $script = '';

    /**
     * @param  array<int, Member[]>  $members  Keyed by group id
     */
    private function __construct(private array $members = [])
    {
    }

    /**
     * @throws UnrecognisedPage when the page has no member list, or one this does not understand
     */
    public static function fromScript(string $script): self
    {
        if (! preg_match('/groupmembers\s*=\s*\{/', $script, $match, PREG_OFFSET_CAPTURE)) {
            throw new UnrecognisedPage('No group members on the groups page');
        }

        $parser = new self();
        $parser->script = $script;
        $parser->at = $match[0][1] + strlen($match[0][0]);
        while (! $parser->take('}')) {
            $group = (int) $parser->expect('/\d+/');
            $parser->expect('/:/');
            $parser->members[$group] = $parser->list(fn () => $parser->member());
            $parser->take(',');
        }

        return $parser;
    }

    /**
     * @return Member[]
     */
    public function of(int $group): array
    {
        return $this->members[$group] ?? [];
    }

    private function member(): Member
    {
        [$id, $text, $confirmed] = $this->list(fn () => $this->value());
        if (! is_int($id) || ! is_string($text) || ! is_bool($confirmed)) {
            throw new UnrecognisedPage('Unexpected group member on the groups page near offset '.$this->at);
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        [$name, $email] = preg_match('/^(?<name>.*?)\s*<(?<email>[^<>]*)>$/s', $text, $parts)
            ? [$parts['name'], $parts['email']]
            : [$text, ''];

        return new Member($id, trim($name), trim($email), $confirmed);
    }

    /**
     * A JavaScript array: [item, item, ...].
     *
     * @template T
     *
     * @param  callable(): T  $item
     * @return list<T>
     */
    private function list(callable $item): array
    {
        $this->expect('/\[/');
        $items = [];
        while (! $this->take(']')) {
            $items[] = $item();
            $this->take(',');
        }

        return $items;
    }

    /**
     * A number, a quoted string or a boolean.
     */
    private function value(): int|string|bool
    {
        $token = $this->expect('/\d+|true|false|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/');

        return match (true) {
            ctype_digit($token) => (int) $token,
            $token === 'true' => true,
            $token === 'false' => false,
            default => self::string(substr($token, 1, -1)),
        };
    }

    /**
     * A JavaScript string's contents. JSON shares its escapes, \uXXXX and surrogate pairs included,
     * once the two it lacks are rewritten: \' and \xHH.
     */
    private static function string(string $contents): string
    {
        $json = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})|\\\\(.)|"/s', fn (array $m) => match (true) {
            $m[0] === '"' => '\\"',
            ($m[1] ?? '') !== '' => '\\u00'.$m[1],
            $m[2] === "'" => "'",
            default => $m[0],
        }, $contents);
        $decoded = json_decode('"'.$json.'"');

        return is_string($decoded) ? $decoded : stripcslashes($contents);
    }

    /**
     * Skip the given character, if it comes next.
     */
    private function take(string $character): bool
    {
        $this->skipSpace();
        if (($this->script[$this->at] ?? '') !== $character) {
            return false;
        }
        $this->at++;

        return true;
    }

    /**
     * @throws UnrecognisedPage when the next token does not match
     */
    private function expect(string $pattern): string
    {
        $this->skipSpace();
        if (! preg_match($pattern.'A', $this->script, $match, 0, $this->at)) {
            throw new UnrecognisedPage('Unexpected group members on the groups page: '.substr($this->script, $this->at, 40));
        }
        $this->at += strlen($match[0]);

        return $match[0];
    }

    private function skipSpace(): void
    {
        $this->at += strspn($this->script, " \t\r\n", $this->at);
        if ($this->at >= strlen($this->script)) {
            throw new UnrecognisedPage('The group members on the groups page end too soon');
        }
    }
}

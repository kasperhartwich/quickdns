<?php

declare(strict_types=1);

namespace QuickDns;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\MissingId;
use QuickDns\Exceptions\NotFound;
use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Internal\ZoneEditSession;
use QuickDns\Parsing\ZoneTable;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class QuickDns
 *
 * @phpstan-consistent-constructor lazy() builds the class it is called on, so a subclass that
 *                                 changes the constructor signature breaks it.
 */
class QuickDns
{
    private string $email;

    private string $password;

    private string $base_uri = 'https://www.quickdns.dk/';

    private ClientInterface $client;

    private CookieJar $cookieJar;

    private bool $loggedIn = false;

    private bool $loggingIn = false;

    private bool $editing = false;

    /**
     * True while an edit session holds a pending table on QuickDNS' side.
     */
    private bool $sessionOpen = false;

    /**
     * The class lazy() is constructing, or null.
     */
    private static ?string $constructLazily = null;

    const METHOD_POST = 'POST';

    const METHOD_GET = 'GET';

    /**
     * QuickDns constructor.
     *
     * @param  ClientInterface|null  $client  Guzzle client to send requests with, e.g. one with your own
     *                                        middleware or a MockHandler. The session cookies are kept
     *                                        by QuickDns, so the client needs no cookie jar.
     */
    public function __construct(string $email, string $password, ?ClientInterface $client = null)
    {
        $this->configure($email, $password, $client);
        if (self::$constructLazily === static::class) {
            self::$constructLazily = null;

            return;
        }
        $this->logInOrFail();
    }

    /**
     * A QuickDns that logs in on its first request instead of right away, once per instance.
     * Useful where the object is built long before it is used, e.g. in a service container.
     * Wrong credentials throw LoginFailed from that first request.
     */
    public static function lazy(string $email, string $password, ?ClientInterface $client = null): static
    {
        // Go through the constructor, so a subclass' own constructor still runs.
        // Keyed by class, so another QuickDns built inside a subclass' constructor is not lazy, and
        // restored afterwards, so a lazy() call inside one does not clear the outer call's flag.
        $previous = self::$constructLazily;
        self::$constructLazily = static::class;
        try {
            return new static($email, $password, $client);
        } finally {
            self::$constructLazily = $previous;
        }
    }

    private function configure(string $email, string $password, ?ClientInterface $client): void
    {
        $this->email = $email;
        $this->password = $password;
        $this->cookieJar = new CookieJar();
        $this->client = $client ?? new Client();
    }

    private function logInOrFail(): void
    {
        $this->loggingIn = true;
        try {
            $loggedIn = $this->login();
        } finally {
            $this->loggingIn = false;
        }
        if (! $loggedIn) {
            throw new LoginFailed('Login failed.');
        }
        $this->loggedIn = true;
    }

    /**
     * Login to QuickDns
     *
     */
    public function login(): bool
    {
        $response = $this->request('login', [
            'email' => $this->email,
            'password' => $this->password,
        ], self::METHOD_POST);
        if (str_contains($response, 'Log ud')) {
            $this->loggedIn = true;

            return true;
        } elseif (str_contains($response, 'Beklager, email-adressen eller passwordet der er indtastet er forkert.')) {
            return false;
        }
        throw new UnrecognisedPage('Unknown response at login');
    }

    /**
     * Get Zones
     *
     * @return Zone[]
     */
    public function getZones(): array
    {
        $zones = [];
        foreach ($this->listRows('zones', 'zone_table') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $zone_data = [$node->getAttribute('zoneid')];
            foreach ($node->getElementsByTagName('td') as $td) {
                $zone_data[] = trim((string) $td->nodeValue);
            }
            // The id plus the six columns. A column more or less means the page changed and the
            // values below would silently be read from the wrong ones.
            if (! ctype_digit($zone_data[0]) || count($zone_data) !== 7) {
                throw new UnrecognisedPage('Unexpected row on the zones page: '.implode(' | ', array_slice($zone_data, 0, 7)));
            }
            //Generate zone
            $zone = new Zone($this, $zone_data[2]);
            // The row carries the ids as well as the names: templates(rowIndex, new Array('17284'))
            // and groups(rowIndex, usearray, editarray).
            $zone->templateIds = $this->idsInCall($node, 'templates', 1);
            $zone->groupIds = $this->idsInCall($node, 'groups', 2);
            $zone->id = (int) $zone_data[0];
            $zone->domain = $zone_data[2];
            $zone->templates = $zone_data[3] == 'Ingen' ? [] : explode(', ', $zone_data[3]);
            $zone->groups = $zone_data[4] == 'Ingen' ? [] : explode(', ', $zone_data[4]);
            $zone->updated = BaseModel::parseUpdated($zone_data[5]);
            $zones[] = $zone;
        }

        return $zones;
    }

    /**
     * Get Zone by Domain
     *
     */
    public function getZone(string $domain): Zone
    {
        foreach ($this->getZones() as $zone) {
            if ($zone->domain == $domain) {
                return $zone;
            }
        }
        throw new NotFound('Unknown domain');
    }

    /**
     * Get the records of a zone, in the order QuickDNS lists them.
     *
     * @return Record[]
     *
     * @throws UnrecognisedPage when the page has no record table
     */
    public function getRecords(Zone|int|string $zone): array
    {
        $id = $this->zoneId($zone);

        return ZoneTable::fromPage($this->page('editzone', ['id' => $id]), 'zone '.$id)->records();
    }

    /**
     * Change a zone's records. Every change inside the closure is sent to QuickDNS as it is made,
     * and the lot is saved when the closure returns. If the closure throws, or QuickDNS rejects a
     * change, nothing is saved: QuickDNS keeps nothing from a session that holds a rejected
     * record, not even the changes it accepted.
     *
     *     $zone->edit(function (RecordSet $records) {
     *         $records->add('www', 'A', '192.0.2.10', ttl: 3600);
     *         $records->remove($records->sole(name: 'old', type: 'A'));
     *     });
     *
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed Whatever the closure returned
     */
    public function editZone(Zone|int|string $zone, callable $changes): mixed
    {
        return $this->editRecords('zone', 'editzone', $this->zoneId($zone), $changes);
    }

    /**
     * Change a template's records. Works exactly like editZone(), and a zone using the template
     * gets the changes.
     *
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed Whatever the closure returned
     */
    public function editTemplate(Template|int|string $template, callable $changes): mixed
    {
        return $this->editRecords('template', 'edittemplate', $this->templateId($template), $changes);
    }

    /**
     * Get a template's records.
     *
     * @return Record[]
     */
    public function getTemplateRecords(Template|int|string $template): array
    {
        $id = $this->templateId($template);

        return ZoneTable::fromPage($this->page('edittemplate', ['id' => $id]), 'template '.$id)->records();
    }

    /**
     * @param  string  $what  'zone' or 'template'
     * @param  string  $page  The page that opens the session
     * @param  int|string  $id
     * @param  callable(RecordSet): mixed  $changes
     * @return mixed
     */
    private function editRecords($what, $page, $id, callable $changes)
    {
        if ($this->editing) {
            throw new \LogicException('A zone or template is already being edited: QuickDNS keeps one pending table per session.');
        }

        $this->editing = true;
        try {
            $session = ZoneEditSession::open($this, (string) $id, $this->page($page, ['id' => $id]), $what);
        } catch (\Throwable $opening) {
            // Opening is a request of its own, and a failed one must not leave the client thinking
            // a zone is still being edited.
            $this->editing = false;

            throw $opening;
        }

        $records = new RecordSet($session);
        $this->sessionOpen = true;
        try {
            $result = $changes($records);
            $session->save();

            return $result;
        } finally {
            $records->close();
            $session->close();
            $this->sessionOpen = false;
            $this->editing = false;
        }
    }

    /**
     * A row must have the columns this library reads, no more and no fewer: a column added or
     * removed would shift every value after it without a word.
     *
     * @throws UnrecognisedPage
     */
    private function expectCells(Crawler $row, int $expected, string $page): void
    {
        $cells = $row->filterXPath('//td')->count();
        if ($cells !== $expected) {
            throw new UnrecognisedPage('A row on the '.$page.' page has '.$cells.' cells, not '.$expected);
        }
    }

    /**
     * The text of a row's nth cell.
     *
     * @throws UnrecognisedPage when the row has no such cell
     */
    private function cell(Crawler $row, int $cell, string $page): string
    {
        $node = $row->filterXPath('//td['.$cell.']');
        if (! $node->count()) {
            throw new UnrecognisedPage('A row on the '.$page.' page has no cell '.$cell);
        }

        return trim($node->text());
    }

    /**
     * An attribute of the node the XPath points at.
     *
     * @throws UnrecognisedPage when the node or the attribute is missing
     */
    private function attribute(Crawler $row, string $xpath, string $attribute, string $page): string
    {
        $node = $row->filterXPath($xpath);

        return ($node->count() ? $node->attr($attribute) : null)
            ?? throw new UnrecognisedPage('A row on the '.$page.' page has no '.$attribute.' at '.$xpath);
    }

    /**
     * The id the pattern picks out. QuickDNS keeps them in hrefs and onclick handlers, and a page
     * that no longer carries one must not quietly become id 0.
     *
     * @throws UnrecognisedPage when the pattern does not match
     */
    private function idIn(string $subject, string $pattern, string $page): int
    {
        if (! preg_match($pattern, $subject, $match)) {
            throw new UnrecognisedPage('No id in "'.$subject.'" on the '.$page.' page');
        }

        return (int) $match[1];
    }

    /**
     * A list cell: names separated by commas, or "Ingen" for none.
     *
     * @return string[]
     */
    private function names(string $cell): array
    {
        return $cell === 'Ingen' || $cell === '' ? [] : explode(', ', $cell);
    }

    /**
     * The ids in one of the zone row's onclick calls, e.g. groups(rowIndex, new Array(), new
     * Array('738')) where the second array holds the groups the zone is a member of.
     *
     * @return int[]
     */
    private function idsInCall(\DOMElement $row, string $function, int $argument): array
    {
        foreach ($row->getElementsByTagName('a') as $link) {
            if (! preg_match('/'.$function.'\((?<arguments>.*)\)/', $link->getAttribute('onclick'), $match)) {
                continue;
            }
            preg_match_all("/new Array\(([^)]*)\)/", $match['arguments'], $arrays);
            $ids = $arrays[1][$argument - 1] ?? '';
            preg_match_all("/'(\d+)'/", $ids, $found);

            return array_map('intval', $found[1]);
        }

        return [];
    }

    /**
     * Set a zone's templates to exactly these, in one request. An empty list removes them all.
     *
     * QuickDNS replaces the whole list every time, so this is the honest shape of the endpoint:
     * Template::addZone() and removeZone() are built on it.
     *
     * @param  array<Template|string|int>  $templates  Templates, their names, or their ids
     *
     * @throws NotFound when the account has no template of that name
     */
    public function setTemplates(Zone|int|string $zone, array $templates): void
    {
        $ids = $this->idsOf($templates, fn (string $name) => $this->getTemplate($name)->id);
        $this->command('updatetemplates', [
            'zone' => $this->zoneId($zone),
            'template' => $ids,
        ]);
        $this->rememberOnZone($zone, 'templateIds', $ids);
    }

    /**
     * Set the groups a zone is in to exactly these, in one request. An empty list removes them all.
     *
     * @param  array<Group|string|int>  $groups  Groups, their names, or their ids
     *
     * @throws NotFound when the account has no group of that name
     */
    public function setGroups(Zone|int|string $zone, array $groups): void
    {
        $ids = $this->idsOf($groups, fn (string $name) => $this->getGroup($name)->id);
        $this->command('updategroups', [
            'zone' => $this->zoneId($zone),
            'group' => $ids,
        ]);
        $this->rememberOnZone($zone, 'groupIds', $ids);
    }

    /**
     * A Zone object carries the ids it was read with, and the next add or remove is built on them,
     * so it has to learn what was just set. Otherwise adding two templates one after the other
     * through the same object keeps only the second.
     *
     * @param  int[]|string[]  $ids
     */
    private function rememberOnZone(Zone|int|string $zone, string $property, array $ids): void
    {
        if ($zone instanceof Zone) {
            $zone->$property = array_map('intval', $ids);
        }
    }

    /**
     * @param  array<BaseModel|string|int>  $items
     * @param  callable(string): (int|string|null)  $lookup
     * @return array<int|string>
     */
    private function idsOf(array $items, callable $lookup): array
    {
        return array_values(array_map(function ($item) use ($lookup) {
            if ($item instanceof BaseModel) {
                return $item->id;
            }

            return is_numeric($item) ? $item : $lookup((string) $item);
        }, $items));
    }

    private function templateId(Template|int|string $template): int|string
    {
        $id = $template instanceof Template ? $template->id : $template;

        return $id ?: throw new MissingId('Template is not created yet.');
    }

    private function zoneId(Zone|int|string $zone): int|string
    {
        $id = $zone instanceof Zone ? $zone->id : $zone;

        return $id ?: throw new MissingId('Zone is not created yet.');
    }

    /**
     * Get Templates
     *
     * @return Template[]
     */
    public function getTemplates(): array
    {
        return $this->listRows('templates', 'zone_table')
            ->each(function (Crawler $tr) {
                $this->expectCells($tr, 6, 'templates');
                $name = $this->cell($tr, 1, 'templates');
                $template = new Template($this, $name);
                $template->id = $this->idIn($this->attribute($tr, '//td[1]/a', 'href', 'templates'), '/\?id=(\d+)(?:&|$)/', 'templates');
                $template->name = $name;
                $template->zones = (int) $this->cell($tr, 2, 'templates');
                $template->groups = $this->names($this->cell($tr, 3, 'templates'));
                $template->updated = BaseModel::parseUpdated($this->cell($tr, 4, 'templates'));

                return $template;
            });
    }

    /**
     * Get Template by Name
     *
     */
    public function getTemplate(string $name): Template
    {
        foreach ($this->getTemplates() as $template) {
            if ($template->name == $name) {
                return $template;
            }
        }
        throw new NotFound('Unknown template');
    }

    /**
     * Get Groups
     *
     * @return Group[] Keyed from 1, as 2.2 returned them
     */
    public function getGroups(): array
    {
        $groups = $this->listRows('groups', 'group_table')
            ->each(function (Crawler $tr) {
                $this->expectCells($tr, 4, 'groups');
                $name = $this->cell($tr, 1, 'groups');
                $group = new Group($this, $name);
                $group->id = $this->idIn($this->attribute($tr, '//td[2]/a', 'onclick', 'groups'), '/\w+\s=\s(\d+);/', 'groups');
                $group->name = $name;
                $group->members = $this->names($this->cell($tr, 2, 'groups'));
                // The groups page shows no time: its third cell is the "Ret" link.

                return $group;
            });

        // 2.2 filtered the header row out of the list, so its keys start at 1. Keep them.
        return $groups ? array_combine(range(1, count($groups)), $groups) : [];
    }

    /**
     * Get Group by Name
     *
     */
    public function getGroup(string $name): Group
    {
        foreach ($this->getGroups() as $group) {
            if ($group->name == $name) {
                return $group;
            }
        }
        throw new NotFound('Unknown group');
    }

    /**
     * Fetch one of QuickDNS' HTML pages.
     *
     * @throws UnrecognisedPage when the answer is not a logged-in QuickDNS page
     */
    protected function page(string $function, array $options = []): Crawler
    {
        $response = $this->request($function, $options);
        if (! str_contains($response, 'Log ud')) {
            throw new UnrecognisedPage('Unexpected page at '.$function.': not logged in');
        }

        return new Crawler($response);
    }

    /**
     * The rows of a list page's table, header rows excluded. QuickDNS always shows the table, with
     * only its header when the list is empty, so a missing table means an unexpected page.
     *
     * @throws UnrecognisedPage when the page has no such table
     */
    private function listRows(string $function, string $tableId): Crawler
    {
        $table = $this->page($function)->filterXPath('//table[@id="'.$tableId.'"]');
        if (! $table->count()) {
            throw new UnrecognisedPage('No '.$tableId.' on the '.$function.' page');
        }

        return $table->filterXPath('.//tr[not(@class="listheader") and not(.//th)]');
    }

    /**
     * Run a QuickDNS command (addzone, delzone, updatetemplates, ...) and return its XML answer,
     * <response><status>OK</status><statustext>...</statustext>...</response>.
     *
     * @throws CommandFailed when QuickDNS answers ERROR, with QuickDNS' statustext as message
     * @throws UnrecognisedPage when the answer is not a command response
     */
    public function command(string $function, array $options = [], string $method = self::METHOD_GET): Crawler
    {
        $xml = $this->xml($function, $options, $method);

        $status = $xml->filterXPath('//response/status');
        if (! $status->count()) {
            throw new UnrecognisedPage('Unexpected response to '.$function);
        }
        if (trim($status->text()) !== 'OK') {
            $statustext = $xml->filterXPath('//response/statustext');
            $fields = [];
            foreach ($xml->filterXPath('//response/*') as $field) {
                if (! in_array($field->nodeName, ['status', 'statustext'], true)) {
                    $fields[$field->nodeName] = trim((string) $field->textContent);
                }
            }
            throw new CommandFailed(
                $statustext->count() ? trim($statustext->text()) : 'QuickDNS answered '.trim($status->text()),
                $function,
                trim($status->text()),
                $fields,
            );
        }

        return $xml;
    }

    /**
     * Request an XML answer and parse it. Commands answer <status>OK</status> or ERROR, but
     * submitzonechange answers a Danish status line instead, so the OK check lives in command().
     *
     * @internal
     */
    public function xml(string $function, array $options = [], string $method = self::METHOD_GET): Crawler
    {
        // Go through request(), which subclasses may override. It strips an XML declaration with a
        // lowercase iso-8859-1 encoding; without one libxml reads UTF-8, so convert first.
        $body = $this->request($function, $options, $method);
        if (! str_starts_with(ltrim($body), '<?xml') && ! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
        $xml = new Crawler();
        $xml->addXmlContent($body);

        return $xml;
    }

    /**
     * Request the API.
     *
     * @internal Use command() or page(), or FakeQuickDns in tests.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request(string $function, array $options = [], string $method = self::METHOD_GET): string
    {
        //Apparently QuickDns declare the html as xml.
        return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', $this->send($function, $options, $method));
    }

    /**
     * Build a query string the way QuickDNS' own pages do.
     *
     * A list is sent as the same parameter repeated: template=1&template=2. PHP's own
     * template[0]=1 is answered with "OK Opdateret" and then quietly ignored, which on
     * updatetemplates means every template is removed, so this must not go through
     * http_build_query.
     *
     * @param  array<string, string|int|array<string|int>>  $options
     * @return string|array<string, string|int>
     */
    private function query(array $options): string|array
    {
        if (! array_filter($options, 'is_array')) {
            return $options;
        }

        $pairs = [];
        foreach ($options as $name => $value) {
            foreach ((array) $value as $one) {
                $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $one);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Send a request and return the raw response body.
     *
     * QuickDNS ends a session after a while without a word, and then answers every request with
     * its login page. Such a request did nothing, so it is sent once more after logging in again.
     * Not in the middle of an edit, though: the pending table went with the session.
     *
     * @param  string  $function  Path relative to https://www.quickdns.dk/, or an absolute URL
     *
     * @throws UnrecognisedPage when QuickDNS logged the client out in the middle of an edit
     */
    private function send(string $function, array $options = [], string $method = self::METHOD_GET): string
    {
        // By the path it resolves to, so an absolute URL to the login page counts as well.
        $isLogin = UriResolver::resolve(new Uri($this->base_uri), new Uri($function))->getPath() === '/login';
        if (! $this->loggedIn && ! $this->loggingIn && ! $isLogin) {
            $this->logInOrFail();
        }

        $body = $this->transmit($function, $options, $method);
        if ($this->loggingIn || $isLogin || ! self::isLoginPage($body)) {
            return $body;
        }
        if ($this->sessionOpen) {
            throw new UnrecognisedPage('QuickDNS logged the client out in the middle of an edit at '.$function.', so nothing was saved.');
        }

        $this->loggedIn = false;
        $this->cookieJar = new CookieJar();
        $this->logInOrFail();

        return $this->transmit($function, $options, $method);
    }

    /**
     * QuickDNS' login page, recognised by its login form. Anything else, an error page or XML
     * that does not parse, is left for the caller to judge, so it is never mistaken for a
     * logged-out answer and sent twice.
     */
    private static function isLoginPage(string $body): bool
    {
        return ! str_contains($body, 'Log ud')
            && preg_match('~<form\s[^>]*action="/login"~i', $body) === 1
            && str_contains($body, 'name="password"');
    }

    private function transmit(string $function, array $options, string $method): string
    {
        if (empty($options)) {
            $options = [];
        } elseif ($method == self::METHOD_POST) {
            $options = ['form_params' => $options];
        } else {
            $options = ['query' => $this->query($options)];
        }
        $options['cookies'] = $this->cookieJar;
        $uri = UriResolver::resolve(new Uri($this->base_uri), new Uri($function));

        return $this->client->request($method, $uri, $options)->getBody()->getContents();
    }
}

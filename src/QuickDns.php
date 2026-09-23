<?php

namespace QuickDns;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\NotFound;
use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Parsing\ZoneTable;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class QuickDns
 */
class QuickDns
{
    private $email;

    private $password;

    private $base_uri = 'https://www.quickdns.dk/';

    private $client;

    private $cookieJar;

    private $loggedIn = false;

    private $loggingIn = false;

    /**
     * The class lazy() is constructing, or null.
     */
    private static $constructLazily = null;

    const METHOD_POST = 'POST';

    const METHOD_GET = 'GET';

    /**
     * QuickDns constructor.
     *
     * @param  string  $email
     * @param  string  $password
     * @param  ClientInterface|null  $client  Guzzle client to send requests with, e.g. one with your own
     *                                        middleware or a MockHandler. The session cookies are kept
     *                                        by QuickDns, so the client needs no cookie jar.
     */
    public function __construct($email, $password, ?ClientInterface $client = null)
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
     *
     * @param  string  $email
     * @param  string  $password
     */
    public static function lazy($email, $password, ?ClientInterface $client = null): static
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

    private function configure($email, $password, ?ClientInterface $client): void
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
     * @return bool
     */
    public function login()
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
     * @return array
     */
    public function getZones()
    {
        $zones = [];
        foreach ($this->listRows('zones', 'zone_table') as $node) {
            $zone_data = [$node->getAttribute('zoneid')];
            foreach ($node->getElementsByTagName('td') as $td) {
                $zone_data[] = trim($td->nodeValue);
            }
            //Generate zone
            $zone = new Zone($this, $zone_data[2]);
            $zone->id = $zone_data[0];
            $zone->domain = $zone_data[2];
            $zone->templates = $zone_data[3] == 'Ingen' ? [] : explode(', ', $zone_data[3]);
            $zone->groups = $zone_data[4] == 'Ingen' ? [] : explode(', ', $zone_data[4]);
            $zone->updated = $zone_data[5];
            $zones[] = $zone;
        }

        return $zones;
    }

    /**
     * Get Zone by Domain
     *
     * @return Zone
     */
    public function getZone($domain)
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
     * @param  Zone|int|string  $zone  A zone or its id
     * @return Record[]
     *
     * @throws UnrecognisedPage when the page has no record table
     */
    public function getRecords($zone): array
    {
        $id = $zone instanceof Zone ? $zone->id : $zone;
        if (! $id) {
            throw new \BadFunctionCallException('Zone is not created yet.');
        }

        return ZoneTable::fromPage($this->page('editzone', ['id' => $id]), 'zone '.$id)->records();
    }

    /**
     * Get Templates
     *
     * @return array
     */
    public function getTemplates()
    {
        return $this->listRows('templates', 'zone_table')
            ->each(function (Crawler $tr) {
                preg_match('/\w+\?id=(\d+)/m', $tr->filterXPath('//td[1]/a')->attr('href'), $match);
                $template = new Template($this, $tr->filterXPath('//td[1]')->text());
                $template->id = (int) $match[1];
                $template->name = $tr->filterXPath('//td[1]')->text();
                $template->zones = (int) $tr->filterXPath('//td[2]')->text();
                $template->groups = $tr->filterXPath('//td[3]')->text() == 'Ingen' ? [] : explode(', ', $tr->filterXPath('//td[3]')->text());
                $template->updated = $tr->filterXPath('//td[4]')->text();

                return $template;
            });
    }

    /**
     * Get Template by Name
     *
     * @return Template
     */
    public function getTemplate($name)
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
     * @return array
     */
    public function getGroups()
    {
        $groups = $this->listRows('groups', 'group_table')
            ->each(function (Crawler $tr) {
                preg_match('/\w+\s\=\s(\d+)\;.+/m', $tr->filterXPath('//td[2]/a')->attr('onclick'), $match);
                $group = new Group($this, $tr->filterXPath('//td[1]')->text());
                $group->id = (int) $match[1];
                $group->name = $tr->filterXPath('//td[1]')->text();
                $group->members = $tr->filterXPath('//td[2]')->text() == 'Ingen' ? [] : explode(', ', $tr->filterXPath('//td[2]')->text());
                $group->updated = $tr->filterXPath('//td[3]')->text();

                return $group;
            });

        // 2.2 filtered the header row out of the list, so its keys start at 1. Keep them.
        return $groups ? array_combine(range(1, count($groups)), $groups) : [];
    }

    /**
     * Get Group by Name
     *
     * @return Group
     */
    public function getGroup($name)
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
     * @param  string  $function
     * @param  array  $options
     * @param  string  $method
     *
     * @throws CommandFailed when QuickDNS answers ERROR, with QuickDNS' statustext as message
     * @throws UnrecognisedPage when the answer is not a command response
     */
    public function command($function, $options = [], $method = self::METHOD_GET): Crawler
    {
        $xml = $this->xml($function, $options, $method);

        $status = $xml->filterXPath('//response/status');
        if (! $status->count()) {
            throw new UnrecognisedPage('Unexpected response to '.$function);
        }
        if (trim($status->text()) !== 'OK') {
            $statustext = $xml->filterXPath('//response/statustext');
            throw new CommandFailed($statustext->count() ? trim($statustext->text()) : 'QuickDNS answered '.trim($status->text()));
        }

        return $xml;
    }

    /**
     * Request an XML answer and parse it. Commands answer <status>OK</status> or ERROR, but
     * submitzonechange answers a Danish status line instead, so the OK check lives in command().
     *
     * @param  string  $function
     * @param  array  $options
     * @param  string  $method
     *
     * @internal
     */
    public function xml($function, $options = [], $method = self::METHOD_GET): Crawler
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
     * Request the API
     *
     * @param  string  $function
     * @param  array  $options
     * @param  string  $method
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request($function, $options = [], $method = self::METHOD_GET): string
    {
        //Apparently QuickDns declare the html as xml.
        return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', $this->send($function, $options, $method));
    }

    /**
     * Send a request and return the raw response body.
     *
     * @param  string  $function  Path relative to https://www.quickdns.dk/, or an absolute URL
     * @param  array  $options
     * @param  string  $method
     */
    private function send($function, $options = [], $method = self::METHOD_GET): string
    {
        if (! $this->loggedIn && ! $this->loggingIn && ltrim($function, '/') !== 'login') {
            $this->logInOrFail();
        }
        if (empty($options)) {
            $options = [];
        } elseif ($method == self::METHOD_POST) {
            $options = ['form_params' => $options];
        } else {
            $options = ['query' => $options];
        }
        $options['cookies'] = $this->cookieJar;
        $uri = UriResolver::resolve(new Uri($this->base_uri), new Uri($function));

        return $this->client->request($method, $uri, $options)->getBody()->getContents();
    }
}

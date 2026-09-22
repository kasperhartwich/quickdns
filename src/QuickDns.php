<?php

namespace QuickDns;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use QuickDns\Exceptions\CommandFailed;
use QuickDns\Exceptions\LoginFailed;
use QuickDns\Exceptions\NotFound;
use QuickDns\Exceptions\UnrecognisedPage;
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
        $this->email = $email;
        $this->password = $password;

        $this->cookieJar = new CookieJar();
        $this->client = $client ?? new Client();
        if (! $this->login()) {
            throw new LoginFailed('Login failed.');
        }
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
        if (strpos($response, 'Log ud')) {
            return true;
        } elseif (strpos($response, 'Beklager, email-adressen eller passwordet der er indtastet er forkert.')) {
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
        $response = $this->request('zones');
        $html = new Crawler($response);
        foreach ($html->filterXPath('//table[@id="zone_table"]//tr[not(@class="listheader")]') as $node) {
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
     * Get Templates
     *
     * @return array
     */
    public function getTemplates()
    {
        $response = $this->request('templates');

        return (new Crawler($response))
            ->filterXPath('//table[@id="zone_table"]//tr[not(@class="listheader")]')
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
        $response = $this->request('groups');

        return array_filter((new Crawler($response))
            ->filterXPath('//table[@id="group_table"]//tr')
            ->each(function (Crawler $tr) {
                if (str_contains($tr->html(), 'listheader')) {
                    return;
                }
                preg_match('/\w+\s\=\s(\d+)\;.+/m', $tr->filterXPath('//td[2]/a')->attr('onclick'), $match);
                $group = new Group($this, $tr->filterXPath('//td[1]')->text());
                $group->id = (int) $match[1];
                $group->name = $tr->filterXPath('//td[1]')->text();
                $group->members = $tr->filterXPath('//td[2]')->text() == 'Ingen' ? [] : explode(', ', $tr->filterXPath('//td[2]')->text());
                $group->updated = $tr->filterXPath('//td[3]')->text();

                return $group;
            }));
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
        $xml = new Crawler();
        $xml->addXmlContent($this->request($function, $options, $method));

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
        if (empty($options)) {
            $options = [];
        } elseif ($method == self::METHOD_POST) {
            $options = ['form_params' => $options];
        } else {
            $options = ['query' => $options];
        }
        $options['cookies'] = $this->cookieJar;
        $response = $this->client->request($method, $this->base_uri.$function, $options);

        //Apparently QuickDns declare the html as xml.
        return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', $response->getBody()->getContents());
    }
}

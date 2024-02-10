<?php

namespace QuickDns;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
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
     */
    public function __construct($email, $password)
    {
        $this->email = $email;
        $this->password = $password;

        $this->cookieJar = new CookieJar();
        $this->client = new Client([
            'base_uri' => $this->base_uri,
            'cookies' => $this->cookieJar,
        ]);
        if (! $this->login()) {
            throw new \InvalidArgumentException('Login failed.');
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
        throw new \UnexpectedValueException('Unknown response at login');
    }

    /**
     * Get Zones
     *
     * @return array
     */
    public function getZones()
    {
        $zones = [];
        $response = $this->request('zones', QuickDns::METHOD_GET);
        $html = new Crawler($response);
        foreach ($html->filterXPath('//table[@id="zone_table"]/tr[not(@class="listheader")]') as $node) {
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
        throw new \UnexpectedValueException('Unknown domain');
    }

    /**
     * Get Templates
     *
     * @return array
     */
    public function getTemplates()
    {
        $response = $this->request('templates', QuickDns::METHOD_GET);

        return (new Crawler($response))
            ->filterXPath('//table[@id="zone_table"]/tr[not(@class="listheader")]')
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
        throw new \UnexpectedValueException('Unknown template');
    }

    /**
     * Get Groups
     *
     * @return array
     */
    public function getGroups()
    {
        $response = $this->request('groups', QuickDns::METHOD_GET);

        return (new Crawler($response))
            ->filterXPath('//table[@id="group_table"]/tr')
            ->each(function (Crawler $tr) {
                if (str_contains($tr->html(), 'listheader')) {
                    return;
                }
                preg_match('/\w+\s\=\s(\d+)\;.+/m', $tr->filterXPath('//td[2]/a')->attr('onclick'), $match);
                $group = new Group($this, $tr->filterXPath('//td[1]')->text());
                $group->id = (int) $match[1];
                $group->name = $tr->filterXPath('//td[1]')->text();
                $group->members = $tr->filterXPath('//td[2]')->text() == 'Ingen' ? [] : explode(', ', $$tr->filterXPath('//td[2]')->text());
                $group->updated = $tr->filterXPath('//td[3]')->text();

                return $group;
            });
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
        throw new \UnexpectedValueException('Unknown group');
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
        if (! empty($options)) {
            if ($method == self::METHOD_POST) {
                $options = ['form_params' => $options];
            } else {
                $options = ['query' => $options];
            }
        }
        $response = $this->client->request($method, $function, $options);

        //        var_dump($response->getStatusCode(),$response->getBody()->getContents());

        //Apparently QuickDns declare the html as xml.
        return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', $response->getBody()->getContents());
    }
}

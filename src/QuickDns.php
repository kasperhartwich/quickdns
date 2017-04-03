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
     * @param string $email
     * @param string $password
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
        if (!$this->login()) {
            throw new \InvalidArgumentException('Login failed.');
        }
    }

    /**
     * Login to QuickDns
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
     * @return array
     */
    public function getZones()
    {
        $zones = [];
        $response = $this->request('zones', QuickDns::METHOD_GET);
        $html = new Crawler($response);
        foreach ($html->filterXPath('//table[@id="zone_table"]/tr[not(@class="listheader")]') as $node) {
            $zone_data = [$node->getAttribute('zoneid')];
            foreach($node->getElementsByTagName('td') as $td) {
                $zone_data[] = trim($td->nodeValue);
            }
            //Generate zone
            $zone = new Zone($this, $zone_data[2]);
            $zone->id = $zone_data[0];
            $zone->domain = $zone_data[2];
            $zone->template = $zone_data[3]=='Ingen' ? false : $zone_data[3];
            $zone->group = $zone_data[4]=='Ingen' ? false : $zone_data[4];
            $zone->updated = $zone_data[5];
            $zones[] = $zone;
        }
        return $zones;
    }

    /**
     * Get Zone by Domain
     * @param $domain
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
     * @return array
     */
    public function getTemplates()
    {
        $templates = [];
        $response = $this->request('templates', QuickDns::METHOD_GET);
        $html = new Crawler($response);
        foreach ($html->filterXPath('//table[@id="zone_table"]/tr[not(@class="listheader")]') as $node) {
            $template_data = [str_replace('/edittemplate?id=', '', $node->firstChild->firstChild->getAttribute('href'))];
            foreach($node->getElementsByTagName('td') as $td) {
                $template_data[] = trim($td->nodeValue);
            }

            //Generate template
            $template = new Template($this, $template_data[1]);
            $template->id = $template_data[0];
            $template->name = $template_data[1];
            $template->zones = $template_data[2];
            $template->group = $template_data[3]=='Ingen' ? false : $template_data[3];
            $template->updated = $template_data[4];
            $templates[] = $template;
        }
        return $templates;
    }

    /**
     * Get Template by Name
     * @param $name
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
     * @return array
     */
    public function getGroups()
    {
        $groups = [];
        $response = $this->request('groups', QuickDns::METHOD_GET);
        $html = new Crawler($response);
        foreach ($html->filterXPath('//table[@id="group_table"]/tr[not(@class="listheader")]') as $node) {
            if ($node->firstChild->nodeValue=='Gruppe') {continue;}
            $group_data = [str_replace(['groupid = ', '; del(parentNode.parentNode.rowIndex);'], '', $node->lastChild->previousSibling->firstChild->getAttribute('onclick'))];
            foreach($node->getElementsByTagName('td') as $td) {
                $group_data[] = trim($td->nodeValue);
            }

            //Generate group
            $group = new Group($this, $group_data[1]);
            $group->id = $group_data[0];
            $group->name = $group_data[1];
            $group->members = $group_data[2]=='Ingen' ? false : $group_data[2];
            $group->updated = $group_data[3];
            $groups[] = $group;
        }
        return $groups;
    }

    /**
     * Get Group by Name
     * @param $name
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
     * @param $function
     * @param array $parameters
     * @param string $method
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function request($function, $parameters = [], $method = self::METHOD_GET)
    {
        if ($method == self::METHOD_POST) {
            $options = ['form_params' => $parameters];
        } else {
            $options = ['query' => $parameters];
        }
        $response = $this->client->request($method, $function, $parameters ? $options : null);
        //Apparently QuickDns declare the html as xml.
        return str_replace('<?xml version="1.0" encoding="iso-8859-1"?>', '', $response->getBody()->getContents());
    }
}

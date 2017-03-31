<?php
namespace QuickDns;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

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
        ]);
        $body = $response->getBody()->getContents();
        var_dump($body);
        if (strpos($body, 'Log ud')) {
            return true;
        } elseif (strpos($body, 'Beklager, email-adressen eller passwordet der er indtastet er forkert.')) {
            return false;
        }
        throw new \UnexpectedValueException('Unknown response at login');
    }

    /**
     * Request the API
     * @param $function
     * @param array $parameters
     * @param string $method
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function request($function, $parameters = [], $method = 'POST')
    {
        return $this->client->post('login', [
            'form_params' => $parameters
        ]);
    }
}

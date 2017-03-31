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
        $body = $response->getBody()->getContents();
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
    private function request($function, $parameters = [], $method = self::METHOD_GET)
    {
        if ($method == self::METHOD_POST) {
            $options = ['form_params' => $parameters];
        } else {
            $options = ['query' => $parameters];
        }
        return $this->client->post($function, $options);
    }
}

<?php

return [

    /*
     * The QuickDNS account to log in with. The client logs in on its first request, so nothing is
     * sent to quickdns.dk until it is used.
     */
    'email' => env('QUICKDNS_EMAIL'),

    'password' => env('QUICKDNS_PASSWORD'),

    /*
     * Optional: the container binding of a GuzzleHttp\ClientInterface to send the requests with,
     * e.g. one with your own logging or rate limiting middleware. Null uses a plain client.
     */
    'client' => null,

];

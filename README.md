QuickDNS [![Build Status](https://travis-ci.org/kasperhartwich/quickdns.svg?branch=master)](https://travis-ci.org/kasperhartwich/quickdns)
========

For how to use, take a look at the tests for now. This is work-in-progress.

### Example
This example creates multiple domains with the same template.

```php
<?php
include "vendor/autoload.php";

$quickDns = new \QuickDns\QuickDns('my@email.example','password');

$domains = <<<EOD
domain1.dk
domain2.dk
domain3.dk
EOD;

$template = $quickDns->getTemplate('my-template');

$domains = explode(PHP_EOL, $domains);
foreach ($domains as $domain) {
    $zone = new \QuickDns\Zone($quickDns, $domain);
    $zone->create();
    echo $zone->domain . ' created' . PHP_EOL;

    $zone = $quickDns->getZone($domain);
    $template->addZone($zone);
    echo $zone->domain . ' added to template ' . $template->name . PHP_EOL;
}
echo 'Done' . PHP_EOL;
```

### Testing
To test, you need to specify email and password for a account at QuickDNS as environment variables.
You can do so by setting the variables in bash when running phpunit:

`QUICKDNS_EMAIL=email@quickdns.dk QUICKDNS_PASSWORD=your-password vendor/bin/phpunit`

Notice: You will need a template on you're account called `test-template` for the tests to succeed.

### License
Licensed under MIT License.

### Contribute
You are more than welcome to contribute. Just create a pull request.

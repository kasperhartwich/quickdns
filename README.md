# QuickDNS 

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kasperhartwich/quickdns.svg?style=flat-square)](https://packagist.org/packages/kasperhartwich/quickdns)

For how to use, take a look at the tests for now. This is work-in-progress.

## Requirements
* PHP 8.1

## Installation

You can install the package via composer:

``` bash
composer require kasperhartwich/quickdns
```

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
You can do so by setting the variables in phpunit.xml file.

You also need to create the template and group `test-template` and `test-group` on your account.

### License
Licensed under MIT License.

### Contribute
You are more than welcome to contribute. Just create a pull request.

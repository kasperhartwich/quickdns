# QuickDNS

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kasperhartwich/quickdns.svg?label=stable)](https://packagist.org/packages/kasperhartwich/quickdns)
[![License](https://img.shields.io/packagist/l/kasperhartwich/quickdns.svg)](LICENSE.txt)
[![Tests](https://img.shields.io/github/actions/workflow/status/kasperhartwich/quickdns/run-tests.yml?branch=main&label=run-tests&logo=github)](https://github.com/kasperhartwich/quickdns/actions/workflows/run-tests.yml)
[![Code Quality](https://img.shields.io/scrutinizer/quality/g/kasperhartwich/quickdns.svg?label=code%20quality)](https://scrutinizer-ci.com/g/kasperhartwich/quickdns/)

A PHP client for [QuickDNS.dk](https://www.quickdns.dk/). Manage zones, templates and groups
from code: create and delete zones, attach them to templates and groups, and list what the
account holds.

QuickDNS has no public API, so the client logs in with the account's email and password and
talks to the same endpoints as the QuickDNS website.

## Requirements

* PHP 8.2 or later

## Installation

```bash
composer require kasperhartwich/quickdns
```

## Usage

Creating the client logs in right away. Wrong credentials throw `QuickDns\Exceptions\LoginFailed`.

```php
use QuickDns\QuickDns;

$quickDns = new QuickDns('my@email.example', 'password');
```

To send the requests through your own Guzzle client (middleware for logging or rate limiting, or
a `MockHandler` in tests), pass it as the third argument. QuickDns keeps the login session
cookies itself, so the client needs no cookie jar:

```php
$quickDns = new QuickDns('my@email.example', 'password', new \GuzzleHttp\Client(['handler' => $stack]));
```

### Zones

```php
use QuickDns\Zone;

foreach ($quickDns->getZones() as $zone) {
    echo $zone->domain, ': ', implode(', ', $zone->templates), PHP_EOL;
}

// Create a zone.
(new Zone($quickDns, 'example.dk'))->create();

// Look a zone up by domain, then delete it.
$quickDns->getZone('example.dk')->delete();
```

### Templates and groups

```php
$template = $quickDns->getTemplate('my-template');
$group = $quickDns->getGroup('my-group');

$zone = $quickDns->getZone('example.dk');
$template->addZone($zone);
$group->addZone($zone);

$template->removeZone($zone);
$group->removeZone($zone);
```

`Template` and `Group` also have `create()` and `delete()`, just like `Zone`.

### Example: set up several domains from one template

```php
use QuickDns\QuickDns;
use QuickDns\Zone;

$quickDns = new QuickDns('my@email.example', 'password');
$template = $quickDns->getTemplate('my-template');

foreach (['domain1.dk', 'domain2.dk', 'domain3.dk'] as $domain) {
    (new Zone($quickDns, $domain))->create();

    // create() does not return the zone's id yet, so fetch the zone before using it.
    $template->addZone($quickDns->getZone($domain));

    echo "{$domain} created and added to {$template->name}", PHP_EOL;
}
```

### Errors

Every exception from QuickDNS implements `QuickDns\Exceptions\QuickDnsException`:

| Exception | When | Extends |
|---|---|---|
| `LoginFailed` | Wrong email or password | `InvalidArgumentException` |
| `CommandFailed` | QuickDNS rejected a command. The message is QuickDNS' own, in Danish, e.g. `Zonen eksisterer allerede` | `InvalidArgumentException` |
| `NotFound` | `getZone()`, `getTemplate()` or `getGroup()` found nothing | `UnexpectedValueException` |
| `UnrecognisedPage` | QuickDNS answered with something unexpected, e.g. a logged-out page | `UnexpectedValueException` |

```php
use QuickDns\Exceptions\CommandFailed;

try {
    (new Zone($quickDns, 'example.dk'))->create();
} catch (CommandFailed $e) {
    echo 'QuickDNS said: ', $e->getMessage(), PHP_EOL;
}
```

## Testing

```bash
composer test
```

runs the offline test suite against recorded QuickDNS pages. No account needed.

The live suite creates and deletes real zones, templates and groups, so run it against a
dedicated test account, never one in use:

```bash
QUICKDNS_EMAIL=test@example.dk QUICKDNS_PASSWORD=secret composer test:live
```

## License

MIT. See [LICENSE.txt](LICENSE.txt).

## Contributing

Pull requests are welcome.

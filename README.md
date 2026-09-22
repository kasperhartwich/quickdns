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

To log in on the first request instead, for example when the client is built in a service
container long before it is used, use `QuickDns::lazy()`. It logs in once per instance, and wrong
credentials throw `LoginFailed` from that first request:

```php
$quickDns = QuickDns::lazy('my@email.example', 'password');
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

// Create a zone. create() sets its id, so it can be used right away.
$zone = (new Zone($quickDns, 'example.dk'))->create();
$zone->delete();

// Or look a zone up by domain.
$quickDns->getZone('example.dk')->delete();
```

### Records

```php
foreach ($quickDns->getZone('example.dk')->getRecords() as $record) {
    echo $record->name, ' ', $record->type, ' ', $record->value, PHP_EOL;  // @ MX mx1.example.dk.
}
```

Each `QuickDns\Record` has `name` (as QuickDNS shows it: `@`, `www`, `*`), `type`, `ttl` and
`priority` (`null` when blank), `value`, `row` (the record's row on the zone page) and `template`
(the template that added the record, or `null`). Records cannot be created or changed yet.

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

`Template` and `Group` also have `create()` and `delete()`, just like `Zone`. QuickDNS does not
answer with a new group's id, so fetch a group with `getGroup()` after creating it.

### Example: set up several domains from one template

```php
use QuickDns\QuickDns;
use QuickDns\Zone;

$quickDns = new QuickDns('my@email.example', 'password');
$template = $quickDns->getTemplate('my-template');

foreach (['domain1.dk', 'domain2.dk', 'domain3.dk'] as $domain) {
    $zone = (new Zone($quickDns, $domain))->create();
    $template->addZone($zone);

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

## Testing your code

`QuickDns\Testing\FakeQuickDns` is an in-memory quickdns.dk. It keeps zones, templates, groups
and records and answers with the same pages as QuickDNS, so code that uses this package can be
tested without the network:

```php
use QuickDns\Testing\FakeQuickDns;
use QuickDns\Zone;

$fake = new FakeQuickDns();
$fake->addTemplate('standard');
$fake->addZone('existing.dk', templates: ['standard']);
$fake->addRecord('existing.dk', '@', 'MX', 'mx1.example.dk.', 3600, 10);

$quickDns = $fake->quickDns(); // or new QuickDns('test@example.dk', 'secret', $fake->client())

(new Zone($quickDns, 'new.dk'))->create();

$fake->hasZone('new.dk');           // true
$fake->templatesOf('existing.dk');  // ['standard']
$fake->requests();                  // every request it answered
```

Like QuickDNS, every new zone gets four NS records from the template "QuickDNS global". To keep
your own middleware, use the fake as the handler: `HandlerStack::create($fake)`.

## Testing this package

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

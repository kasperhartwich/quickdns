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

* PHP 8.3 or later

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
(the template that added the record, or `null`). `isLocked()` is true for template records: they
can only be changed on the template itself.

### Writing records

`edit()` runs one QuickDNS edit session: every change is sent as it is made, and the lot is saved
when the closure returns.

```php
use QuickDns\RecordSet;
use QuickDns\RecordType;

$zone = $quickDns->getZone('example.dk');

$zone->edit(function (RecordSet $records) {
    $records->add('www', 'A', '192.0.2.10', ttl: 3600);
    $records->add('@', RecordType::MX, 'mx1.example.dk.', ttl: 3600, priority: 10);

    $spf = $records->sole(name: '@', type: RecordType::TXT);
    $records->replace($spf, $spf->withValue('v=spf1 include:_spf.example.dk ~all'));

    $records->remove($records->sole(name: 'old', type: 'A'));
});
```

Find records with `find(name:, type:, value:)`, `where(...)` or `sole(...)`, which throws unless
exactly one matches. `all()` returns them in page order, and the set is iterable and countable.

**Nothing is saved unless everything works.** If the closure throws, or QuickDNS rejects a change,
the session is discarded. That is QuickDNS' own behaviour: it saves nothing from a session that
holds a rejected record, not even the changes it accepted.

For a single change there are one-shot helpers, each its own session:

```php
$record = $zone->addRecord('www', 'A', '192.0.2.10', ttl: 3600);
$zone->replaceRecord($record, $record->withValue('192.0.2.11'));
$zone->deleteRecord($record);
```

What QuickDNS accepts, checked against the service:

| | |
|---|---|
| Types | `RecordType`: A, AAAA, CNAME, MX, NS, PTR, SPF, SRV, TXT |
| Priority | MX and SRV only. Changing the type to another one clears it. |
| TTL | Any number of seconds, or `null` to inherit. `Record::TTLS` holds the values QuickDNS' own dropdown offers. |
| Names and values | Printable ASCII, and never `"`, `'` or `\`. Danish letters are rejected here, although they are fine in a template or group name. |
| Duplicates | QuickDNS accepts them silently, so `add()` refuses a record the zone already has. Pass `allowDuplicates: true` to add it anyway. |
| Template records | Cannot be changed or deleted on the zone: `RecordLocked`. |

A record that breaks one of the first four rules throws `InvalidRecord` before anything is sent.
What only QuickDNS can judge throws `RecordRejected`, which carries its Danish messages in
`errors()`, the rows in `rows()` and the records in `records()`.

### Templates and groups

```php
$template = $quickDns->getTemplate('my-template');
$group = $quickDns->getGroup('my-group');

$zone = $quickDns->getZone('example.dk');
$template->addZone($zone);      // keeps the zone's other templates
$group->addZone($zone);

$template->removeZone($zone);   // takes off only this one
$group->removeZone($zone);
```

A zone can use several templates, and `$zone->templates` lists their names. To set the whole list
at once, by name, id or object:

```php
$quickDns->setTemplates($zone, ['my-template', 'another']);
$quickDns->setTemplates($zone, []);              // removes them all
$quickDns->setGroups($zone, ['my-group']);
```

`Template` and `Group` also have `create()`, `delete()` and `rename()`, just like `Zone`. QuickDNS
does not answer with a new group's id, so fetch a group with `getGroup()` after creating it.

### A template's records

A template holds records of its own, and every zone using it gets them. They read and write
exactly like a zone's, and on the zone they show up as locked:

```php
$template = $quickDns->getTemplate('my-template');

foreach ($template->getRecords() as $record) {
    echo $record->name, ' ', $record->type, ' ', $record->value, PHP_EOL;
}

$template->edit(function (RecordSet $records) {
    $records->add('@', RecordType::MX, 'mx1.example.dk.', ttl: 3600, priority: 10);
    $records->add('www', 'A', '192.0.2.10', ttl: 3600);
});

$template->addRecord('mail', 'A', '192.0.2.20', ttl: 3600);
$template->rename('another-name');
```

A template's records are applied to each zone exactly as they are written. Nothing is rewritten:

- **Names are relative to the zone.** `@` is the zone's apex, `www` is `www.` plus the zone, and `*` is
  the wildcard. On a zone, the template row `www A 192.0.2.2` shows as `www A 192.0.2.2`, locked.
- **`@` is the only placeholder.** As a value it also means the zone's apex, so `alias CNAME @` and
  `@ MX @` work on every zone. There is no `{domain}`, `$DOMAIN` or similar. On a zone, a value
  containing `{`, `}` or `$` is rejected ("indeholder ugyldige tegn").
- **Any other target is literal.** A CNAME, MX or NS target outside the zone needs its trailing dot
  (`mail.example.dk.`). QuickDNS refuses a CNAME to a bare name such as `www`.

**A template can be saved and still be unusable.** The template page checks less than a zone does,
so a record the zone would refuse is only caught when the template is applied. Then
`addZone()` and `setTemplates()` throw `CommandFailed` ("De valgte skabeloner giver 1 fejl i
zonen"). Nothing is applied, and the zone keeps the templates it had. Records added to a template
that zones already use show up on those zones straight away.

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

Every exception from QuickDNS extends the abstract `QuickDns\Exceptions\QuickDnsException`:

| Exception | When | Extends |
|---|---|---|
| `LoginFailed` | Wrong email or password | `QuickDnsException` |
| `CommandFailed` | QuickDNS rejected a command. The message is QuickDNS' own, in Danish, e.g. `Zonen eksisterer allerede` | `QuickDnsException` |
| `NotFound` | `getZone()`, `getTemplate()` or `getGroup()` found nothing | `QuickDnsException` |
| `MissingId` | The zone, template or group has no id yet, so it cannot be changed | `QuickDnsException` |
| `InvalidRecord` | A record QuickDNS would reject, caught before sending | `QuickDnsException` |
| `RecordLocked` | The record belongs to a template | `InvalidRecord` |
| `RecordRejected` | QuickDNS rejected a change, so the edit was discarded | `CommandFailed` |
| `StaleRecord` | The record was replaced or removed earlier in the same edit | `QuickDnsException` |
| `UnrecognisedPage` | QuickDNS answered with something unexpected, e.g. a logged-out page | `QuickDnsException` |

Using the library wrongly, such as starting an edit inside another, throws `LogicException`
instead: that is a bug to fix, not something QuickDNS said.

`CommandFailed` also says what failed: `function()` is the command, `status()` QuickDNS' status,
`statusText()` its message and `fields()` the rest of its answer.

```php
use QuickDns\Exceptions\CommandFailed;

try {
    (new Zone($quickDns, 'example.dk'))->create();
} catch (CommandFailed $e) {
    echo $e->function(), ' failed: ', $e->statusText(), PHP_EOL;
}
```

## Laravel

The service provider is discovered automatically. Set the account in `.env`:

```dotenv
QUICKDNS_EMAIL=my@email.example
QUICKDNS_PASSWORD=password
```

and use `QuickDns\QuickDns` from the container (it logs in on first use), or the facade:

```php
use QuickDns\Laravel\Facades\QuickDns;

$zones = QuickDns::getZones();
```

`php artisan vendor:publish --tag=quickdns-config` publishes `config/quickdns.php`, where
`client` can name a container binding of a `GuzzleHttp\ClientInterface` to send the requests
with. In tests, `QuickDns::fake()` swaps in a [FakeQuickDns](#testing-your-code) and returns it.

## Symfony

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    QuickDns\Symfony\QuickDnsBundle::class => ['all' => true],
];
```

and configure it in `config/packages/quickdns.yaml`:

```yaml
quickdns:
    email: '%env(QUICKDNS_EMAIL)%'
    password: '%env(QUICKDNS_PASSWORD)%'
    # client: my_guzzle_client   # optional service id of a GuzzleHttp\ClientInterface
```

`QuickDns\QuickDns` is then autowirable. It logs in on first use.

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
$fake->recordsOf('existing.dk');    // the zone's records after your writes
$fake->requests();                  // every request it answered
```

The fake writes records too, with the same rules, the same locked template rows and the same
all-or-nothing saving. `$fake->failNextChange('...')` makes the next change fail the way QuickDNS
would, and `$fake->hasPendingChanges('example.dk')` shows whether an edit was left open.

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

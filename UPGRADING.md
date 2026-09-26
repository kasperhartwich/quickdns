# Upgrading

## From 2.x to 3.0

3.0 needs PHP 8.3, as 2.7 does. Much of what changed fails loudly: a type error, a removed
property that names its replacement, a method that no longer exists. These can change what your
code does without an error, so look for them first:

1. **`catch (\InvalidArgumentException)` and `catch (\UnexpectedValueException)`** around this
   library no longer catch its exceptions. See [Exceptions](#exceptions).
2. **`Zone::$id` is an int.** A strict comparison with the old string stops matching. See
   [`Zone::$id` is an int](#zoneid-is-an-int).
3. **The constructor no longer logs in**, so wrong credentials throw from the first request
   instead of from `new`. See [Logging in](#logging-in).
4. **Models no longer change in place.** `$zone->create()`, `$template->rename()` and
   `$template->addZone($zone)` leave the object they were called on as it was, and return the new
   state. Code that ignores the return value keeps using the old one. See
   [Models are final and immutable](#models-are-final-and-immutable).
5. **`getGroups()` is indexed from 0**, so `getGroups()[1]` is now the second group. See
   [Groups](#groups).

New in 3.0, besides the changes below: the client logs in again by itself when QuickDNS has ended
its session, and resends the request once.

### Exceptions

`QuickDns\Exceptions\QuickDnsException` is now an abstract class instead of an interface, and it
is the only parent of the library's exceptions. They no longer extend PHP's SPL exceptions:

| Exception | Extended in 2.x | Extends in 3.0 |
|---|---|---|
| `LoginFailed` | `InvalidArgumentException` | `QuickDnsException` |
| `CommandFailed` | `InvalidArgumentException` | `QuickDnsException` |
| `InvalidRecord` | `InvalidArgumentException` | `QuickDnsException` |
| `StaleRecord` | `InvalidArgumentException` | `QuickDnsException` |
| `NotFound` | `UnexpectedValueException` | `QuickDnsException` |
| `UnrecognisedPage` | `UnexpectedValueException` | `QuickDnsException` |

A `catch (\InvalidArgumentException $e)` or `catch (\UnexpectedValueException $e)` around this
library no longer catches what QuickDNS says. Catch the class you mean, or `QuickDnsException` for
all of them. Two things are unchanged: using the library wrongly (a second edit inside an edit, a
`RecordSet` used after its edit ended) still throws `LogicException`, and `FakeQuickDns`' setup
helpers still throw `InvalidArgumentException` for a zone, template or group the fake does not have.

Calling something that needs an id on a zone, template or group that has none (`delete()`,
`edit()`, `getRecords()`, `rename()`, ...) throws the new `MissingId` instead of
`BadFunctionCallException`.

`CommandFailed` now says what failed: `function()`, `status()`, `statusText()` and `fields()`. On
`RecordRejected`, `status()` is still QuickDNS' status line, e.g. "Status: 1 fejl i zonen".

### `$updated` is a DateTimeImmutable

`Zone::$updated` and `Template::$updated` were the text the list page shows, "2026-09-22 18:21:28".
They are now a `DateTimeImmutable` in Europe/Copenhagen, the time zone QuickDNS shows its times in,
or null when the page shows none. In the hour the clocks go back, which happens twice, the page does
not say which of the two it was, so the time can be an hour off. Format it yourself where you used
the string:

```php
$zone->updated?->format('Y-m-d H:i:s');
```

`Group::$updated` is removed, see Groups below.

### `Zone::$id` is an int

`Zone::$id` was a string ("17287"), from `getZones()` and from `create()` alike, while every other
id was an int. It is now an int too. This one fails silently: a strict comparison such as
`$zone->id === '17287'`, or `in_array($zone->id, $ids, true)` against a list of strings, stops
matching without an error. So does a `string` parameter or property the id is passed to under
`strict_types`, though that one at least throws a TypeError. Search your code for places that
compare or store zone ids.

### Groups

- `getGroups()` returns a list indexed from 0. 2.x kept the keys from 1 that 2.2 had.
- `Group::$members` held the text of the list cell, which only counts the members (`['2 medlemmer']`),
  or `[]` for "Ingen". It now holds `QuickDns\Member` objects read from the page's member list, each
  with `id`, `name`, `email` and `confirmed` (false while an invitation is not accepted).
- `Group::$updated` is gone. 2.x filled it with "Ret", the label of the rename link, since the groups
  page shows no time.

### Models are final and immutable

`Zone`, `Template` and `Group` are now `final readonly` classes on an abstract `BaseModel`. Their
properties can no longer be assigned, and they cannot be extended.

- **Ids and fields go in through the constructor:** `new Zone($quickDns, 'example.dk', 17287)`
  rather than setting `$zone->id` afterwards. `new Zone($quickDns, 'example.dk')` still describes a
  zone that is about to be created.
- **`create()` returns a new object carrying the id**, and the one you called it on keeps a null
  id. Write `$zone = (new Zone($quickDns, 'example.dk'))->create();`, as the README always has. A
  group's `create()` now reads the groups page to find the id QuickDNS does not answer with.
- **`delete()` returns nothing** instead of `true`. It throws when it fails, as before.
- **`rename()` returns the renamed object**, and the one you called it on keeps the old name.
- **`Template::addZone()`, `removeZone()` and their `Group` counterparts return the `Zone`** as
  QuickDNS shows it afterwards, where 2.x returned the template or group. They always read the
  zone's current list from QuickDNS first, and do nothing when there is nothing to change. 2.x
  trusted the list the `Zone` object was read with.
- **`requireId()`** returns the id or throws `MissingId`.
- **Removed properties throw.** Reading a property that 3.0 removed throws a `LogicException` naming
  the replacement, for example `Group::$updated`, rather than giving null and a warning. So does any
  other unknown property.

### `Template::$zones` is `$zoneCount`

`Template::$zones` was the number of zones using the template, despite its name. It is now
`$zoneCount`, and reading `$zones` throws a `LogicException` that says so. New methods return
the related objects themselves, each read from QuickDNS when called:

- `Template::zones()`: the zones using the template
- `Template::groups()`: the groups it is shared with
- `Zone::templates()`: the templates the zone uses
- `Zone::groups()`: the groups the zone is in

The properties `Zone::$templates`, `Zone::$groups` and `Template::$groups` still hold the names.

### Logging in

- **The constructor no longer logs in.** `new QuickDns($email, $password)` sends nothing, and the
  client logs in before its first request. Wrong credentials therefore throw `LoginFailed` from
  that first request, not from `new`. Call `login()` right after creating the client to keep the
  old behaviour.
- **`QuickDns::lazy()` is deprecated.** It is now the same as the constructor. It stays until a
  later major version.
- **`login()` returns nothing.** It throws `LoginFailed` for a wrong email or password, where it
  used to return false. A subclass that overrides it has to change its signature to `: void`.
- **`isLoggedIn()`** says whether the client holds a login session.
- **A `QuickDns` subclass that overrides `request()`** and answers every request itself is no
  longer asked to log in, since the automatic login happens below `request()`.

### Ids are ints, names are strings

- **Ids are ints.** `getRecords()`, `editZone()`, `setTemplates()` and `setGroups()` take a `Zone` or
  its id as an int, and `getTemplateRecords()` and `editTemplate()` take a `Template` or its int id.
  A numeric string such as `'17287'` is no longer accepted: cast it with `(int)`.
- **Strings are names.** In the lists `setTemplates()` and `setGroups()` take, an int is an id and
  a string is a name, always. 2.x read any numeric string as an id, so a template called `2024` was
  taken to be template 2024.
- **`Zone::create()`'s parameter is `$getData`**, not `$get_data`. This only matters for named
  arguments.

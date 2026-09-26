# Upgrading

## From 2.x to 3.0

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

`Group::$updated` held the text "Ret", the label of the groups page's rename link, and is now always
null: QuickDNS shows no time for a group.

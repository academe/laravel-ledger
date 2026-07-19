# Formatting and parsing amounts

[← Back to README](../README.md)

Every amount in and out of the package is a `Money` value in integer minor
units — the package deliberately has no float-based API. For turning those
values into display strings (and back), `Academe\LaravelJournal\Support\MoneyFormatter`
wraps the moneyphp formatter/parser boilerplate.

Four static methods, two per direction; each direction offers a plain
decimal flavour and a locale-aware flavour:

| Method | Direction | ext-intl | Example |
| --- | --- | --- | --- |
| `decimal(Money)` | Money → string | not needed | `Money::GBP(123456)` → `"1234.56"` |
| `format(Money, ?locale, ?style)` | Money → string | required | `Money::GBP(123456)` → `"£1,234.56"` |
| `parseDecimal(string, currency)` | string → Money | not needed | `('1234.56', 'GBP')` → `Money::GBP(123456)` |
| `parse(string, currency, ?locale, ?style)` | string → Money | required | `('£1,234.56', 'GBP')` → `Money::GBP(123456)` |

```php
use Academe\LaravelJournal\Support\MoneyFormatter;

MoneyFormatter::decimal(Money::GBP(123456));     // "1234.56" — no ext-intl needed
MoneyFormatter::format(Money::GBP(123456));      // "£1,234.56" — locale-aware, needs ext-intl
MoneyFormatter::parseDecimal('1234.56', 'GBP');  // Money::GBP(123456)
MoneyFormatter::parse('£1,234.56', 'GBP');       // Money::GBP(123456) — needs ext-intl
```

`format()` and `parse()` default to the application locale; pass a locale as
the next argument (`'de_DE'`), and optionally a `NumberFormatter` style
constant as the last (`NumberFormatter::DECIMAL` keeps the locale's digit
grouping but drops the currency symbol). Currency arguments accept an ISO
code string or a `Money\Currency` instance. Parsing never infers the
currency from a symbol in the string — you always say which currency you
expect.

## ext-intl

The `intl` PHP extension is **not** a package dependency. `format()` and
`parse()` require it and throw a clear `RuntimeException` when it isn't
loaded; `decimal()` and `parseDecimal()` have no extension requirement and
work everywhere. For machine-facing strings — APIs, CSV exports, values
round-tripped through your own forms — the decimal pair is the safe
default; reserve the locale-aware pair for text shown to or typed by
people.

## Localised parsing is locale-sensitive

A locale-formatted string is ambiguous without its locale: `"1.234"` is one
thousand two hundred and thirty-four in `de_DE` but a fraction over one in
`en_GB`. `parse()` interprets the string strictly according to the locale
you give it (or the application locale by default), so always parse with
the locale the string was produced in — input typed by a user formatting
numbers their own way needs that locale passed explicitly. When the input
is machine-generated, prefer `parseDecimal()`, which accepts only the
unambiguous plain-decimal form.

## Extending

The class is deliberately not `final`, and its internals are protected
extension points: subclass and override `defaultLocale()` (locale
resolution), `currencies()` (the currency list), or `makeNumberFormatter()`
(the underlying `NumberFormatter` — rounding mode, custom pattern). The
built `NumberFormatter` instances are memoized per locale and style, so
formatting a report of hundreds of amounts constructs one, not hundreds;
overriding `makeNumberFormatter()` keeps that memoization.

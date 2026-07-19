<?php

declare(strict_types=1);

use Academe\LaravelJournal\Support\MoneyFormatter;
use Money\Currency;
use Money\Money;

// ---------------------------------------------------------------------
// Decimal pair — no ext-intl required.
// ---------------------------------------------------------------------

it('formats money as a plain decimal string', function () {
    expect(MoneyFormatter::decimal(Money::GBP(123456)))->toBe('1234.56');
});

it('formats negative and zero amounts as plain decimals', function () {
    expect(MoneyFormatter::decimal(Money::GBP(-123456)))->toBe('-1234.56')
        ->and(MoneyFormatter::decimal(Money::GBP(0)))->toBe('0.00');
});

it('formats zero-decimal currencies without a fraction part', function () {
    expect(MoneyFormatter::decimal(new Money(500, new Currency('JPY'))))->toBe('500');
});

it('parses a plain decimal string into money', function () {
    expect(MoneyFormatter::parseDecimal('1234.56', 'GBP'))
        ->toEqual(Money::GBP(123456));
});

it('parses a plain decimal string with a Currency instance', function () {
    expect(MoneyFormatter::parseDecimal('1234.56', new Currency('GBP')))
        ->toEqual(Money::GBP(123456));
});

it('round-trips money through decimal and parseDecimal', function () {
    $money = Money::GBP(987654321);

    expect(MoneyFormatter::parseDecimal(MoneyFormatter::decimal($money), 'GBP'))
        ->toEqual($money);
});

// ---------------------------------------------------------------------
// Intl pair — needs ext-intl; guard must fail cleanly without it.
// ---------------------------------------------------------------------

it('formats money with a currency symbol for a locale', function () {
    expect(MoneyFormatter::format(Money::GBP(123456), 'en_GB'))
        ->toBe('£1,234.56');
})->skip(! extension_loaded('intl'), 'ext-intl not loaded');

it('parses a locale-formatted string into money', function () {
    expect(MoneyFormatter::parse('£1,234.56', 'GBP', 'en_GB'))
        ->toEqual(Money::GBP(123456));
})->skip(! extension_loaded('intl'), 'ext-intl not loaded');

it('round-trips money through format and parse in a non-English locale', function () {
    $money = Money::EUR(123456);

    $formatted = MoneyFormatter::format($money, 'de_DE');

    expect(MoneyFormatter::parse($formatted, 'EUR', 'de_DE'))->toEqual($money);
})->skip(! extension_loaded('intl'), 'ext-intl not loaded');

it('formats without a symbol using the decimal style', function () {
    expect(MoneyFormatter::format(Money::GBP(123456), 'en_GB', NumberFormatter::DECIMAL))
        ->toBe('1,234.56');
})->skip(! extension_loaded('intl'), 'ext-intl not loaded');

it('defaults the locale to the application locale', function () {
    config()->set('app.locale', 'de_DE');

    expect(MoneyFormatter::format(Money::EUR(123456)))
        ->toBe(MoneyFormatter::format(Money::EUR(123456), 'de_DE'));
})->skip(! extension_loaded('intl'), 'ext-intl not loaded');

it('reuses one NumberFormatter per locale and style', function () {
    $formatter = new class extends MoneyFormatter
    {
        public static int $built = 0;

        protected static function makeNumberFormatter(string $locale, int $style): NumberFormatter
        {
            self::$built++;

            return parent::makeNumberFormatter($locale, $style);
        }
    };

    $formatter::format(Money::GBP(100), 'en_GB');
    $formatter::format(Money::GBP(200), 'en_GB');
    $formatter::parse('£3.00', 'GBP', 'en_GB');

    expect($formatter::$built)->toBe(1);

    $formatter::format(Money::GBP(100), 'en_GB', NumberFormatter::DECIMAL);

    expect($formatter::$built)->toBe(2);
})->skip(! extension_loaded('intl'), 'ext-intl not loaded');

it('throws a clear exception from format when ext-intl is missing', function () {
    $formatter = new class extends MoneyFormatter
    {
        protected static function intlLoaded(): bool
        {
            return false;
        }
    };

    $formatter::format(Money::GBP(123456));
})->throws(RuntimeException::class, 'intl');

it('throws a clear exception from parse when ext-intl is missing', function () {
    $formatter = new class extends MoneyFormatter
    {
        protected static function intlLoaded(): bool
        {
            return false;
        }
    };

    $formatter::parse('£1,234.56', 'GBP');
})->throws(RuntimeException::class, 'intl');

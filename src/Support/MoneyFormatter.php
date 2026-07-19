<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Support;

use Illuminate\Container\Container;
use Money\Currencies;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Formatter\IntlLocalizedDecimalFormatter;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Money\Parser\IntlLocalizedDecimalParser;
use NumberFormatter;
use RuntimeException;

/**
 * Converts Money values to and from strings, wrapping the moneyphp
 * formatter and parser boilerplate. Not tied to the journal models —
 * usable anywhere a Money needs displaying or reading.
 *
 * Two flavours per direction:
 *
 *  - decimal() / parseDecimal() — plain decimal strings ("1234.56"),
 *    no symbol, no grouping, no PHP extensions required.
 *  - format() / parse() — locale-aware strings ("£1,234.56"),
 *    requiring the intl PHP extension.
 *
 * The locale defaults to the application locale when running inside a
 * Laravel app, falling back to 'en' outside one. $style is a
 * NumberFormatter style constant: CURRENCY (the default) includes the
 * currency symbol; DECIMAL keeps locale grouping but drops the symbol.
 */
class MoneyFormatter
{
    /**
     * Memoized NumberFormatter per class, locale, and style. ICU
     * formatter construction is the expensive part of a format()/parse()
     * call, and a report formats many amounts with the same pair; the
     * formatters are reusable because nothing here mutates them.
     *
     * @var array<string, NumberFormatter>
     */
    protected static array $numberFormatters = [];

    /**
     * Format as a plain decimal string in the currency's subunits,
     * e.g. GBP 123456 -> "1234.56". No symbol, grouping, or ext-intl.
     */
    public static function decimal(Money $money): string
    {
        return (new DecimalMoneyFormatter(static::currencies()))->format($money);
    }

    /**
     * Format for a locale, e.g. GBP 123456 -> "£1,234.56" in en_GB.
     *
     * @throws RuntimeException when ext-intl is not loaded
     */
    public static function format(Money $money, ?string $locale = null, ?int $style = null): string
    {
        static::requireIntl();

        $style ??= NumberFormatter::CURRENCY;

        $numberFormatter = static::numberFormatter(
            $locale ?? static::defaultLocale(),
            $style,
        );

        // IntlMoneyFormatter always renders the currency symbol, whatever
        // the style; other styles go through the localized decimal
        // formatter so DECIMAL keeps grouping but drops the symbol.
        $formatter = $style === NumberFormatter::CURRENCY
            ? new IntlMoneyFormatter($numberFormatter, static::currencies())
            : new IntlLocalizedDecimalFormatter($numberFormatter, static::currencies());

        return $formatter->format($money);
    }

    /**
     * Parse a plain decimal string, e.g. ("1234.56", 'GBP') ->
     * GBP 123456. No ext-intl required.
     */
    public static function parseDecimal(string $value, Currency|string $currency): Money
    {
        return (new DecimalMoneyParser(static::currencies()))
            ->parse($value, static::currency($currency));
    }

    /**
     * Parse a locale-formatted string, e.g. ("£1,234.56", 'GBP') ->
     * GBP 123456. The currency is required; it is not inferred from
     * any symbol in the string.
     *
     * @throws RuntimeException when ext-intl is not loaded
     */
    public static function parse(
        string $value,
        Currency|string $currency,
        ?string $locale = null,
        ?int $style = null,
    ): Money {
        static::requireIntl();

        $numberFormatter = static::numberFormatter(
            $locale ?? static::defaultLocale(),
            $style ?? NumberFormatter::CURRENCY,
        );

        return (new IntlLocalizedDecimalParser($numberFormatter, static::currencies()))
            ->parse($value, static::currency($currency));
    }

    /**
     * The locale used when none is given: the application locale
     * inside a booted Laravel app, 'en' otherwise.
     */
    protected static function defaultLocale(): string
    {
        $container = Container::getInstance();

        if ($container->bound('config')) {
            return (string) $container->make('config')->get('app.locale', 'en');
        }

        return 'en';
    }

    /**
     * @throws RuntimeException when ext-intl is not loaded
     */
    protected static function requireIntl(): void
    {
        if (! static::intlLoaded()) {
            throw new RuntimeException(
                'The intl PHP extension is required for locale-aware formatting; '
                .'install ext-intl or use decimal() / parseDecimal() instead.',
            );
        }
    }

    protected static function intlLoaded(): bool
    {
        return extension_loaded('intl');
    }

    protected static function numberFormatter(string $locale, int $style): NumberFormatter
    {
        // Keyed by class as well, so a subclass overriding
        // makeNumberFormatter() never shares entries with its parent.
        return static::$numberFormatters[static::class.'|'.$locale.'|'.$style]
            ??= static::makeNumberFormatter($locale, $style);
    }

    /**
     * The uncached factory: override this to customise the formatter
     * (rounding mode, pattern, ...) without losing the memoization.
     */
    protected static function makeNumberFormatter(string $locale, int $style): NumberFormatter
    {
        return new NumberFormatter($locale, $style);
    }

    /**
     * The currency list used by every formatter and parser.
     */
    protected static function currencies(): Currencies
    {
        return new ISOCurrencies;
    }

    protected static function currency(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : new Currency($currency);
    }
}

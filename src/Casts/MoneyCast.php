<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;
use Money\Money;

/**
 * Casts an integer minor-units column plus a currency code column
 * to a Money\Money object.
 *
 * The default column names are {key}_currency and {key}_amount;
 * both can be overridden with cast arguments:
 * MoneyCast::class . ':currency_code,balance'.
 *
 * @implements CastsAttributes<Money, Money|null>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        protected ?string $currencyColumn = null,
        protected ?string $amountColumn = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $currencyCode = $attributes[$this->currencyColumn ?? $key.'_currency'] ?? null;
        $minorUnits = $attributes[$this->amountColumn ?? $key.'_amount'] ?? null;

        if ($currencyCode === null || $minorUnits === null) {
            return null;
        }

        return new Money($minorUnits, new Currency($currencyCode));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->currencyColumn ?? $key.'_currency' => null,
                $this->amountColumn ?? $key.'_amount' => null,
            ];
        }

        return [
            $this->currencyColumn ?? $key.'_currency' => $value->getCurrency()->getCode(),
            $this->amountColumn ?? $key.'_amount' => $value->getAmount(),
        ];
    }
}

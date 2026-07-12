<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;

/**
 * Casts an ISO 4217 currency code column to a Money\Currency object.
 * The column name defaults to the cast key and can be overridden
 * with a cast argument: CurrencyCast::class . ':currency_code'.
 *
 * @implements CastsAttributes<Currency, Currency|null>
 */
class CurrencyCast implements CastsAttributes
{
    public function __construct(
        protected ?string $columnName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Currency
    {
        $code = $value ?: ($attributes[$this->columnName ?? $key] ?? null);

        return $code ? new Currency($code) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$this->columnName ?? $key => $value?->getCode()];
    }
}

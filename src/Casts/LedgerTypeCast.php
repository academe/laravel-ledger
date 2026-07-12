<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Casts;

use Academe\LaravelJournal\Contracts\LedgerType;
use Academe\LaravelJournal\Enums\StandardLedgerType;
use Academe\LaravelJournal\Exceptions\InvalidLedgerType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a stored ledger type code to a LedgerType enum case, resolved
 * through the journal.ledger_types registry. A backed enum casts
 * natively, but an interface cannot, and the registry is what lets an
 * application add its own ledger type enums alongside the standard
 * five.
 *
 * @implements CastsAttributes<LedgerType, mixed>
 */
class LedgerTypeCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?LedgerType
    {
        if ($value === null) {
            return null;
        }

        return $this->resolve((string) $value);
    }

    /**
     * Accepts a registered LedgerType case, or a bare code string which
     * is validated against the registry before storing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof LedgerType) {
            if (! in_array($value::class, $this->registeredTypes(), true)) {
                throw new InvalidLedgerType(sprintf(
                    'Ledger type enum %s is not registered in journal.ledger_types.',
                    $value::class,
                ));
            }

            return (string) $value->value;
        }

        if (is_string($value)) {
            return (string) $this->resolve($value)->value;
        }

        throw new InvalidLedgerType(sprintf(
            'Ledger type must be a LedgerType case or code string, %s given.',
            get_debug_type($value),
        ));
    }

    /**
     * @throws InvalidLedgerType when no registered enum defines the code
     */
    protected function resolve(string $code): LedgerType
    {
        foreach ($this->registeredTypes() as $enum) {
            $type = $enum::tryFrom($code);

            if ($type !== null) {
                return $type;
            }
        }

        throw new InvalidLedgerType(sprintf(
            "Ledger type code '%s' is not defined by any enum registered in journal.ledger_types.",
            $code,
        ));
    }

    /**
     * @return array<int, class-string<LedgerType>>
     */
    protected function registeredTypes(): array
    {
        return config('journal.ledger_types', [StandardLedgerType::class]);
    }
}

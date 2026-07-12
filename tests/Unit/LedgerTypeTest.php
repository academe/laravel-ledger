<?php

declare(strict_types=1);

use Academe\LaravelJournal\Enums\LedgerType;

it('defines the five ledger types', function () {
    expect(array_map(fn (LedgerType $t) => $t->value, LedgerType::cases()))
        ->toBe(['asset', 'expense', 'liability', 'equity', 'income']);
});

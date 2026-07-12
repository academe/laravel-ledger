<?php

declare(strict_types=1);

use Academe\LaravelJournal\Contracts\LedgerType;
use Academe\LaravelJournal\Enums\BalanceSide;
use Academe\LaravelJournal\Enums\StandardLedgerType;

it('defines the five ledger types', function () {
    expect(array_map(fn (StandardLedgerType $t) => $t->value, StandardLedgerType::cases()))
        ->toBe(['asset', 'expense', 'liability', 'equity', 'income']);
});

it('implements the LedgerType contract', function () {
    expect(StandardLedgerType::ASSET)->toBeInstanceOf(LedgerType::class);
});

it('reports the normal balance side of each type', function () {
    expect(StandardLedgerType::ASSET->normalBalance())->toBe(BalanceSide::Debit)
        ->and(StandardLedgerType::EXPENSE->normalBalance())->toBe(BalanceSide::Debit)
        ->and(StandardLedgerType::LIABILITY->normalBalance())->toBe(BalanceSide::Credit)
        ->and(StandardLedgerType::EQUITY->normalBalance())->toBe(BalanceSide::Credit)
        ->and(StandardLedgerType::INCOME->normalBalance())->toBe(BalanceSide::Credit);
});

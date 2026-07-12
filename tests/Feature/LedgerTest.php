<?php

declare(strict_types=1);

use Academe\LaravelJournal\Enums\LedgerType;
use Academe\LaravelJournal\Models\Ledger;
use Money\Currency;
use Money\Money;

it('casts the ledger type to the enum', function () {
    $ledger = Ledger::create(['name' => 'Assets', 'type' => 'asset']);

    expect($ledger->fresh()->type)->toBe(LedgerType::ASSET);
});

it('relates journals assigned to the ledger', function () {
    $books = companyBooks();

    expect($books->assetsLedger->journals)->toHaveCount(2);
    expect($books->arJournal->fresh()->ledger->is($books->assetsLedger))->toBeTrue();
});

it('computes a debit-positive balance for asset ledgers', function () {
    $books = companyBooks();

    $books->cashJournal->debit(Money::USD(5000));
    $books->arJournal->debit(Money::USD(2500));
    $books->cashJournal->credit(Money::USD(1000));

    expect($books->assetsLedger->currentBalance('USD'))->toEqual(Money::USD(6500));
});

it('computes a credit-positive balance for income ledgers', function () {
    $books = companyBooks();

    $books->incomeJournal->credit(Money::USD(7500));

    expect($books->incomeLedger->currentBalance(new Currency('USD')))->toEqual(Money::USD(7500));
});

it('keeps asset and income ledgers in agreement across user journals', function () {
    $books = companyBooks();

    foreach (range(1, 5) as $i) {
        $user = fixtureUser("user{$i}@example.com");
        $journal = $user->initJournal('USD');
        $journal->assignToLedger($books->incomeLedger);

        $journal->credit(Money::USD(10000));
        $books->arJournal->debit(Money::USD(10000));
    }

    expect($books->arJournal->currentBalance())->toEqual(Money::USD(-50000));
    expect($books->assetsLedger->currentBalance('USD'))
        ->toEqual($books->incomeLedger->currentBalance('USD'))
        ->toEqual(Money::USD(50000));
});

it('ignores other currencies when summing', function () {
    $books = companyBooks();
    $gbpJournal = fixtureUser('gbp@example.com')->initJournal('GBP');
    $gbpJournal->assignToLedger($books->assetsLedger);

    $books->cashJournal->debit(Money::USD(5000));
    $gbpJournal->debit(new Money(9999, new Currency('GBP')));

    expect($books->assetsLedger->currentBalance('USD'))->toEqual(Money::USD(5000));
});

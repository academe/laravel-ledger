<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Models\JournalTransaction;
use Money\Currency;
use Money\Money;

it('credits and debits a journal and tracks the current balance', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(10000));

    expect($journal->currentBalance())->toEqual(Money::USD(10000));
    expect($journal->currentBalance()->getAmount())->toBe('10000');

    $journal->debit(Money::USD(10099));

    expect($journal->currentBalance())->toEqual(Money::USD(-99));
});

it('accepts integer minor units in the journal currency', function () {
    $journal = makeUserJournal('GBP');

    $transaction = $journal->credit(2500, 'top-up');

    expect($transaction)->toBeInstanceOf(JournalTransaction::class);
    expect($transaction->credit)->toEqual(new Money(2500, new Currency('GBP')));
    expect($transaction->memo)->toBe('top-up');
    expect($journal->currentBalance())->toEqual(new Money(2500, new Currency('GBP')));
});

it('records absolute values regardless of sign', function () {
    $journal = makeUserJournal();

    $journal->debit(-500);

    expect($journal->currentBalance())->toEqual(Money::USD(-500));
});

it('rejects a Money value in a different currency', function () {
    $journal = makeUserJournal('GBP');

    $journal->credit(Money::USD(100));
})->throws(CurrencyMismatch::class);

it('caches the balance on the journal row after each post', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(750));

    expect($journal->fresh()->balance)->toEqual(Money::USD(750));

    $journal->debit(Money::USD(250));

    expect($journal->fresh()->balance)->toEqual(Money::USD(500));
});

it('recomputes the cached balance when a transaction is deleted', function () {
    $journal = makeUserJournal();

    $keep = $journal->credit(Money::USD(1000));
    $remove = $journal->credit(Money::USD(400));

    $remove->delete();

    expect($journal->fresh()->balance)->toEqual(Money::USD(1000));
});

it('computes balances as of a date and separates future transactions', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(100), 'yesterday', now()->subDay());
    $journal->credit(Money::USD(200), 'today');
    $journal->credit(Money::USD(400), 'future', now()->addDays(7));
    $journal->debit(Money::USD(50), 'yesterday debit', now()->subDay());

    expect($journal->balanceOn(now()->subDay()))->toEqual(Money::USD(50));
    expect($journal->currentBalance())->toEqual(Money::USD(250));
    expect($journal->totalBalance())->toEqual(Money::USD(650));
    expect($journal->debitBalanceOn(now()))->toEqual(Money::USD(50));
    expect($journal->creditBalanceOn(now()))->toEqual(Money::USD(300));
});

it('stamps posts with the transaction group when given', function () {
    $journal = makeUserJournal();

    $transaction = $journal->credit(Money::USD(100), null, null, 'abc-123');

    expect($transaction->transaction_group)->toBe('abc-123');
});

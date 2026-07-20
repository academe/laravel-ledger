<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\JournalNotInLedger;
use Illuminate\Support\Carbon;
use Money\Money;

it('defaults balanceOn to now, excluding future-dated transactions', function () {
    $books = companyBooks();

    $books->cashJournal->credit(Money::USD(1000));
    $books->cashJournal->credit(Money::USD(500), 'future', Carbon::now()->addDays(2));

    expect($books->cashJournal->balanceOn())->toEqual(Money::USD(1000));
    expect($books->cashJournal->balanceOn())->toEqual($books->cashJournal->currentBalance());
});

it('reports a debit-normal journal debit-positive via normalBalanceOn', function () {
    $books = companyBooks();

    $books->cashJournal->debit(Money::USD(9900));

    expect($books->cashJournal->currentBalance())->toEqual(Money::USD(-9900));
    expect($books->cashJournal->normalBalanceOn())->toEqual(Money::USD(9900));
});

it('reports a credit-normal journal unchanged via normalBalanceOn', function () {
    $books = companyBooks();

    $books->incomeJournal->credit(Money::USD(7500));
    $books->incomeJournal->debit(Money::USD(500));

    expect($books->incomeJournal->normalBalanceOn())->toEqual(Money::USD(7000));
    expect($books->incomeJournal->normalBalanceOn())
        ->toEqual($books->incomeJournal->currentBalance());
});

it('bounds normalBalanceOn to the end of the given day', function () {
    $books = companyBooks();

    $books->cashJournal->debit(Money::USD(1000), 'early', Carbon::parse('2026-01-10 09:00'));
    $books->cashJournal->debit(Money::USD(400), 'late', Carbon::parse('2026-01-20 09:00'));

    expect($books->cashJournal->normalBalanceOn(Carbon::parse('2026-01-10')))
        ->toEqual(Money::USD(1000));
    expect($books->cashJournal->normalBalanceOn(Carbon::parse('2026-01-20')))
        ->toEqual(Money::USD(1400));
});

it('refuses a normal balance for a journal in no ledger', function () {
    makeUserJournal()->normalBalanceOn();
})->throws(JournalNotInLedger::class, 'not assigned to a ledger');

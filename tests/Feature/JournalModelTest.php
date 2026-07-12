<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Tests\Fixtures\Models\User;
use Money\Currency;
use Money\Money;

it('creates a journal with a zero balance by default', function () {
    $journal = makeUserJournal();

    expect($journal->fresh()->balance)->toEqual(new Money(0, new Currency('USD')));
});

it('casts the journal currency', function () {
    $journal = makeUserJournal('GBP');

    expect($journal->currency)->toEqual(new Currency('GBP'));
});

it('resolves the journal owner morph', function () {
    $journal = makeUserJournal();

    expect($journal->owner)->toBeInstanceOf(User::class);
});

it('generates uuid transaction keys', function () {
    $journal = makeUserJournal();

    $transaction = new JournalTransaction([
        'currency_code' => 'USD',
        'credit' => new Money(100, new Currency('USD')),
        'post_date' => now(),
    ]);
    $journal->transactions()->save($transaction);

    expect($transaction->id)->toBeString()->toHaveLength(36);
    expect($transaction->journal->is($journal))->toBeTrue();
});

it('exposes credit and debit as Money and amount as signed Money', function () {
    $journal = makeUserJournal();

    $credit = new JournalTransaction([
        'currency_code' => 'USD',
        'credit' => new Money(150, new Currency('USD')),
        'post_date' => now(),
    ]);
    $journal->transactions()->save($credit);

    $debit = new JournalTransaction([
        'currency_code' => 'USD',
        'debit' => new Money(60, new Currency('USD')),
        'post_date' => now(),
    ]);
    $journal->transactions()->save($debit);

    expect($credit->fresh()->credit)->toEqual(new Money(150, new Currency('USD')));
    expect($credit->fresh()->amount)->toEqual(new Money(150, new Currency('USD')));
    expect($debit->fresh()->debit)->toEqual(new Money(60, new Currency('USD')));
    expect($debit->fresh()->amount)->toEqual(new Money(-60, new Currency('USD')));
});

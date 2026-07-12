<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Tests\Fixtures\Models\Product;
use Money\Currency;
use Money\Money;

it('initialises a journal with an explicit currency', function () {
    $user = fixtureUser();

    $journal = $user->initJournal('USD');

    expect($journal)->toBeInstanceOf(Journal::class);
    expect($user->fresh()->journal->is($journal))->toBeTrue();
    expect($journal->currency)->toEqual(new Currency('USD'));
    expect($journal->balance)->toEqual(Money::USD(0));
});

it('initialises a journal with the configured base currency', function () {
    $journal = fixtureUser()->initJournal();

    expect($journal->currency)->toEqual(new Currency('GBP'));
});

it('accepts a Currency object', function () {
    $journal = fixtureUser()->initJournal(new Currency('EUR'));

    expect($journal->currency_code)->toBe('EUR');
});

it('refuses to initialise a second journal', function () {
    $user = fixtureUser();
    $user->initJournal('USD');
    $user->fresh()->initJournal('USD');
})->throws(JournalAlreadyExists::class);

it('refuses a second journal on the same in-memory instance', function () {
    $user = fixtureUser('same-instance@example.com');
    $user->initJournal('USD');
    $user->initJournal('USD');
})->throws(JournalAlreadyExists::class);

it('exposes transactions referencing a model', function () {
    $user = fixtureUser();
    $journal = $user->initJournal('USD');
    $product = Product::create(['name' => 'Widget', 'price' => 999]);

    $transaction = $journal->credit(Money::USD(999));
    $transaction->reference()->associate($product)->save();

    expect($product->journalTransactions)->toHaveCount(1);
    expect($product->journalTransactions->first()->is($transaction))->toBeTrue();
    expect($transaction->fresh()->reference->is($product))->toBeTrue();
});

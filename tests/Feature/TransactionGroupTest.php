<?php

declare(strict_types=1);

use Academe\LaravelJournal\Enums\EntryType;
use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Tests\Fixtures\Models\Product;
use Academe\LaravelJournal\TransactionGroup;
use Money\Money;

it('rejects methods other than credit or debit', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'banana', Money::USD(100));
})->throws(InvalidJournalMethod::class);

it('accepts EntryType enum cases as the method', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, EntryType::Debit, Money::USD(10000))
        ->addTransaction($books->arJournal, EntryType::Credit, Money::USD(10000))
        ->commit();

    expect($books->cashJournal->currentBalance())
        ->toEqual($books->arJournal->currentBalance()->multiply(-1));
});

it('normalises method strings to EntryType in the pending queue', function () {
    $books = companyBooks();

    $pending = TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(100))
        ->addTransaction($books->arJournal, EntryType::Credit, Money::USD(100))
        ->pending();

    expect($pending[0]['method'])->toBe(EntryType::Debit);
    expect($pending[1]['method'])->toBe(EntryType::Credit);
});

it('rejects zero amounts', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(0));
})->throws(InvalidJournalEntryValue::class);

it('rejects negative amounts', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(-100));
})->throws(InvalidJournalEntryValue::class);

it('rejects unbalanced groups at commit', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(9901))
        ->addTransaction($books->arJournal, 'credit', Money::USD(9900))
        ->commit();
})->throws(DebitsAndCreditsDoNotEqual::class);

it('posts balanced groups so journal values mirror each other', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(10000))
        ->addTransaction($books->arJournal, 'credit', Money::USD(10000))
        ->commit();

    expect($books->cashJournal->currentBalance())
        ->toEqual($books->arJournal->currentBalance()->multiply(-1));
});

it('stamps every entry with the returned group uuid', function () {
    $books = companyBooks();

    $groupUuid = TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(10000))
        ->addTransaction($books->arJournal, 'credit', Money::USD(10000))
        ->addTransaction($books->cashJournal, 'debit', Money::USD(7500))
        ->addTransaction($books->arJournal, 'credit', Money::USD(7500))
        ->commit();

    expect(JournalTransaction::where('transaction_group', $groupUuid)->count())->toBe(4);
});

it('persists memo, post date, and reference on committed entries', function () {
    $books = companyBooks();
    $product = Product::create(['name' => 'Widget', 'price' => 999]);
    $postDate = now()->subDays(3);

    $groupUuid = TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(999), 'sale', $product, $postDate)
        ->addTransaction($books->incomeJournal, 'credit', Money::USD(999), 'sale', $product, $postDate)
        ->commit();

    $entries = JournalTransaction::where('transaction_group', $groupUuid)->get();

    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        expect($entry->memo)->toBe('sale');
        expect($entry->post_date->toDateString())->toBe($postDate->toDateString());
        expect($entry->reference->is($product))->toBeTrue();
    }
});

it('keeps ledgers balanced after a group commit', function () {
    $books = companyBooks();

    $value = (int) (mt_rand(1000000, 9999999) * 1.987654321);

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD($value))
        ->addTransaction($books->incomeJournal, 'credit', Money::USD($value))
        ->commit();

    expect($books->assetsLedger->currentBalance('USD'))->toEqual(Money::USD($value));
    expect($books->incomeLedger->currentBalance('USD'))->toEqual(Money::USD($value));
});

it('keeps ledgers balanced after complex activity', function () {
    $books = companyBooks();

    foreach (range(1, 1000) as $i) {
        $a = (int) (mt_rand(1, 99999999) * 2.25);
        $b = (int) (mt_rand(1, 99999999) * 3.50);

        TransactionGroup::make()
            ->addTransaction($books->cashJournal, 'debit', Money::USD($a))
            ->addTransaction($books->arJournal, 'debit', Money::USD($b))
            ->addTransaction($books->incomeJournal, 'credit', Money::USD($a + $b))
            ->commit();
    }

    expect($books->assetsLedger->currentBalance('USD'))
        ->toEqual($books->incomeLedger->currentBalance('USD'));
});

it('rejects numerically balanced but cross-currency groups', function () {
    $books = companyBooks();
    $gbpJournal = fixtureUser('gbp@example.com')->initJournal('GBP');

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'credit', Money::USD(100))
        ->addTransaction($gbpJournal, 'debit', Money::GBP(100))
        ->commit();
})->throws(DebitsAndCreditsDoNotEqual::class);

it('commits multi-currency groups that are balanced per currency', function () {
    $books = companyBooks();
    $gbpCreditJournal = fixtureUser('gbp-credit@example.com')->initJournal('GBP');
    $gbpDebitJournal = fixtureUser('gbp-debit@example.com')->initJournal('GBP');

    $groupUuid = TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'credit', Money::USD(100))
        ->addTransaction($books->arJournal, 'debit', Money::USD(100))
        ->addTransaction($gbpCreditJournal, 'credit', Money::GBP(50))
        ->addTransaction($gbpDebitJournal, 'debit', Money::GBP(50))
        ->commit();

    expect(JournalTransaction::where('transaction_group', $groupUuid)->count())->toBe(4);
    expect($books->cashJournal->fresh()->balance)->toEqual(Money::USD(100));
    expect($books->arJournal->fresh()->balance)->toEqual(Money::USD(-100));
    expect($gbpCreditJournal->fresh()->balance)->toEqual(Money::GBP(50));
    expect($gbpDebitJournal->fresh()->balance)->toEqual(Money::GBP(-50));
});

it('rolls back every entry and reports the original exception when a mid-commit entry fails', function () {
    $books = companyBooks();
    $gbpJournal = fixtureUser('gbp@example.com')->initJournal('GBP');

    try {
        TransactionGroup::make()
            ->addTransaction($books->cashJournal, 'debit', Money::USD(100))
            ->addTransaction($gbpJournal, 'credit', Money::USD(100))
            ->commit();

        $this->fail('Expected TransactionCouldNotBeProcessed to be thrown.');
    } catch (TransactionCouldNotBeProcessed $e) {
        expect($e->getPrevious())->toBeInstanceOf(CurrencyMismatch::class);
    }

    expect(JournalTransaction::count())->toBe(0);
    expect($books->cashJournal->fresh()->balance)->toEqual(Money::USD(0));
});

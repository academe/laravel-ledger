<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\TransactionGroup;
use Money\Money;

it('rejects posting into a closed period', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(5));

    $journal->credit(Money::USD(100), 'backdated', now()->subDays(10));
})->throws(PeriodClosed::class);

it('rejects posting dated exactly on the lock date', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(5));

    $journal->credit(Money::USD(100), 'on the closed day', now()->subDays(5)->setTime(12, 0, 0));
})->throws(PeriodClosed::class);

it('allows posting after the lock date', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $transaction = $journal->credit(Money::USD(40), 'open period', now()->subDays(2));

    expect($transaction->exists)->toBeTrue();
    expect($journal->fresh()->balance)->toEqual(Money::USD(140));
});

it('rejects editing a frozen transaction', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $transaction->memo = 'rewriting history';
    $transaction->save();
})->throws(PeriodClosed::class);

it('rejects moving a transaction into a frozen period', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now());
    $journal->checkpoint(now()->subDays(5));

    $transaction->post_date = now()->subDays(10);
    $transaction->save();
})->throws(PeriodClosed::class);

it('rejects deleting a frozen transaction', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $transaction->delete();
})->throws(PeriodClosed::class);

it('locks and totals agree immediately after checkpointing', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint->credit_total)->toEqual(Money::USD(100));
    expect(fn () => $journal->credit(Money::USD(1), null, now()->subDays(6)))
        ->toThrow(PeriodClosed::class);
    expect($journal->fresh()->balance)->toEqual(Money::USD(100));
});

it('rolls back a transaction group touching a closed period', function () {
    $books = companyBooks();
    $books->cashJournal->checkpoint(now()->subDays(5));

    $caught = null;

    try {
        TransactionGroup::make()
            ->addTransaction($books->incomeJournal, 'credit', Money::USD(100), null, null, now()->subDays(10))
            ->addTransaction($books->cashJournal, 'debit', Money::USD(100), null, null, now()->subDays(10))
            ->commit();
    } catch (TransactionCouldNotBeProcessed $caught) {
    }

    expect($caught)->toBeInstanceOf(TransactionCouldNotBeProcessed::class);
    expect($caught->getPrevious())->toBeInstanceOf(PeriodClosed::class);
    expect($caught->getMessage())
        ->toContain('could not be processed: ')
        ->toContain('is closed through');
    expect(JournalTransaction::count())->toBe(0);
    expect($books->incomeJournal->fresh()->balance)->toEqual(Money::USD(0));
});

it('carries structured data when creating into a closed period', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(5));

    try {
        $journal->credit(Money::USD(100), 'backdated', now()->subDays(10));
        $this->fail('PeriodClosed was not thrown.');
    } catch (PeriodClosed $e) {
        expect($e->journal->is($journal))->toBeTrue();
        expect($e->lockedUntil->toDateString())->toBe(now()->subDays(5)->toDateString());
        expect($e->postDate->toDateString())->toBe(now()->subDays(10)->toDateString());
    }
});

it('carries structured data when updating a frozen transaction', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    try {
        $transaction->memo = 'rewriting history';
        $transaction->save();
        $this->fail('PeriodClosed was not thrown.');
    } catch (PeriodClosed $e) {
        expect($e->journal->is($journal))->toBeTrue();
        expect($e->lockedUntil->toDateString())->toBe(now()->subDays(5)->toDateString());
        expect($e->postDate->toDateString())->toBe(now()->subDays(10)->toDateString());
    }
});

it('carries structured data when deleting a frozen transaction', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    try {
        $transaction->delete();
        $this->fail('PeriodClosed was not thrown.');
    } catch (PeriodClosed $e) {
        expect($e->journal->is($journal))->toBeTrue();
        expect($e->lockedUntil->toDateString())->toBe(now()->subDays(5)->toDateString());
        expect($e->postDate->toDateString())->toBe(now()->subDays(10)->toDateString());
    }
});

it('names the journal through the owner in the PeriodClosed message', function () {
    $journal = makeUserJournal(); // User owner, no NamesJournal: "User #{id}"
    $journal->checkpoint(now()->subDays(5));

    try {
        $journal->credit(Money::USD(100), null, now()->subDays(10));
        $this->fail('PeriodClosed was not thrown.');
    } catch (PeriodClosed $e) {
        expect($e->getMessage())->toBe(sprintf(
            'Journal "User #%s" is closed through %s; cannot post, change, or delete a transaction dated %s.',
            $journal->owner_id,
            now()->subDays(5)->toDateString(),
            now()->subDays(10)->toDateString(),
        ));
    }
});

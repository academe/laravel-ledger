<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\TransactionGroup;
use Illuminate\Support\Facades\DB;
use Money\Money;

/**
 * Count UPDATE statements against the journals balance cache issued
 * while the callback runs.
 */
function countJournalBalanceUpdates(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => str_starts_with($query['query'], 'update "journals" set "balance"'),
    ));

    DB::disableQueryLog();

    return $count;
}

it('defaults to on_commit mode', function () {
    expect(config('journal.balance_update'))->toBe('on_commit');
});

it('recomputes once per journal for a bulk import in one transaction', function () {
    $journal = makeUserJournal();

    $updates = countJournalBalanceUpdates(function () use ($journal) {
        DB::transaction(function () use ($journal) {
            foreach (range(1, 10) as $i) {
                $journal->credit(Money::USD(100));
            }
        });
    });

    expect($updates)->toBe(1);
    expect($journal->fresh()->balance)->toEqual(Money::USD(1000));
});

it('defers the cached balance until the transaction commits', function () {
    $journal = makeUserJournal();

    DB::transaction(function () use ($journal) {
        $journal->credit(Money::USD(500));

        // The cached column is stale inside the transaction...
        expect($journal->fresh()->balance)->toEqual(Money::USD(0));

        // ...but computed balances are already correct.
        expect($journal->totalBalance())->toEqual(Money::USD(500));
    });

    expect($journal->fresh()->balance)->toEqual(Money::USD(500));
});

it('updates the cached balance before a standalone posting returns', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(250));

    expect($journal->fresh()->balance)->toEqual(Money::USD(250));
});

it('never recomputes for a transaction that rolls back', function () {
    $journal = makeUserJournal();

    try {
        DB::transaction(function () use ($journal) {
            $journal->credit(Money::USD(500));

            throw new RuntimeException('abort import');
        });
    } catch (RuntimeException) {
    }

    expect(JournalTransaction::count())->toBe(0);
    expect($journal->fresh()->balance)->toEqual(Money::USD(0));

    // The mechanism recovers: a later posting recomputes normally.
    $journal->credit(Money::USD(70));

    expect($journal->fresh()->balance)->toEqual(Money::USD(70));
});

it('recomputes each distinct journal once per transaction group', function () {
    $books = companyBooks();

    $updates = countJournalBalanceUpdates(function () use ($books) {
        TransactionGroup::make()
            ->addTransaction($books->cashJournal, 'debit', Money::USD(100))
            ->addTransaction($books->arJournal, 'credit', Money::USD(100))
            ->addTransaction($books->cashJournal, 'debit', Money::USD(50))
            ->addTransaction($books->arJournal, 'credit', Money::USD(50))
            ->commit();
    });

    expect($updates)->toBe(2);
    expect($books->cashJournal->fresh()->balance)->toEqual(Money::USD(-150));
    expect($books->arJournal->fresh()->balance)->toEqual(Money::USD(150));
});

it('recomputes on every write in immediate mode', function () {
    config(['journal.balance_update' => 'immediate']);

    $journal = makeUserJournal();

    DB::transaction(function () use ($journal) {
        $journal->credit(Money::USD(500));

        // No deferral: the cache is fresh inside the transaction.
        expect($journal->fresh()->balance)->toEqual(Money::USD(500));
    });

    expect($journal->fresh()->balance)->toEqual(Money::USD(500));
});

it('defers delete recomputes too', function () {
    $journal = makeUserJournal();
    $remove = $journal->credit(Money::USD(300));
    $journal->credit(Money::USD(100));

    $updates = countJournalBalanceUpdates(function () use ($remove) {
        DB::transaction(function () use ($remove) {
            $remove->delete();
        });
    });

    expect($updates)->toBe(1);
    expect($journal->fresh()->balance)->toEqual(Money::USD(100));
});

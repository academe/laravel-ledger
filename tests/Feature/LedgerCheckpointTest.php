<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Money\Money;

it('checkpoints every journal in the ledger', function () {
    $books = companyBooks();
    $books->cashJournal->debit(Money::USD(5000), null, now()->subDays(10));
    $books->arJournal->debit(Money::USD(2500), null, now()->subDays(10));

    $count = $books->assetsLedger->checkpoint(now()->subDays(5));

    expect($count)->toBe(2);
    expect($books->cashJournal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
    expect($books->arJournal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
    expect($books->cashJournal->latestCheckpoint()->debit_total)->toEqual(Money::USD(5000));
});

it('rolls the whole ledger checkpoint back when one journal fails', function () {
    $books = companyBooks();

    // Ledger::checkpoint() iterates journals()->orderBy('id')->get(), a
    // documented contract; cashJournal has the higher id, so it's
    // iterated second, already has a later checkpoint, and the bulk
    // operation fails AFTER arJournal's checkpoint has been written —
    // proving that write is rolled back.
    $books->cashJournal->checkpoint(now()->subDays(2));

    expect(fn () => $books->assetsLedger->checkpoint(now()->subDays(5)))
        ->toThrow(InvalidCheckpointDate::class);

    expect($books->arJournal->fresh()->locked_until)->toBeNull();
    expect($books->arJournal->checkpoints()->count())->toBe(0);
});

it('removes checkpoints across the ledger', function () {
    $books = companyBooks();
    $books->assetsLedger->checkpoint(now()->subDays(10));
    $books->assetsLedger->checkpoint(now()->subDays(5));

    $removed = $books->assetsLedger->removeCheckpointsSince(now()->subDays(5));

    expect($removed)->toBe(2); // one per member journal
    expect($books->cashJournal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(10)->toDateString());
});

it('a journal without a ledger checkpoints independently', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint->credit_total)->toEqual(Money::USD(100));
    expect($journal->ledger_id)->toBeNull();
});

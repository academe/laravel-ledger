<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Academe\LaravelJournal\Models\JournalCheckpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Money\Money;

it('creates a first checkpoint with cumulative totals and locks the journal', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(10000), 'old income', now()->subDays(10));
    $journal->debit(Money::USD(4000), 'old cost', now()->subDays(9));
    $journal->credit(Money::USD(700), 'recent', now());

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint)->toBeInstanceOf(JournalCheckpoint::class);
    expect($checkpoint->credit_total)->toEqual(Money::USD(10000));
    expect($checkpoint->debit_total)->toEqual(Money::USD(4000));
    expect($checkpoint->currency_code)->toBe('USD');
    expect($journal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
});

it('builds later checkpoints incrementally to the same result as a full sum', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(100), 'window 1', now()->subDays(30));
    $journal->debit(Money::USD(30), 'window 2', now()->subDays(15));
    $journal->credit(Money::USD(50), 'window 2', now()->subDays(12));
    $journal->credit(Money::USD(999), 'open tail', now());

    $journal->checkpoint(now()->subDays(20));
    $second = $journal->checkpoint(now()->subDays(10));

    // Second checkpoint = window 1 + window 2, exactly what a full
    // recompute through subDays(10) gives.
    expect($second->credit_total)->toEqual(Money::USD(150));
    expect($second->debit_total)->toEqual(Money::USD(30));

    expect($journal->checkpoints()->count())->toBe(2);
    expect($journal->latestCheckpoint()->checkpoint_date->toDateString())
        ->toBe(now()->subDays(10)->toDateString());
});

it('rejects a checkpoint dated on or before the latest', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(10));

    $journal->checkpoint(now()->subDays(10));
})->throws(InvalidCheckpointDate::class);

it('rejects a checkpoint dated before the latest', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(10));

    $journal->checkpoint(now()->subDays(20));
})->throws(InvalidCheckpointDate::class);

it('accepts a string date', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(500), null, now()->subDays(10));

    $checkpoint = $journal->checkpoint(now()->subDays(5)->toDateString());

    expect($checkpoint->credit_total)->toEqual(Money::USD(500));
});

it('includes transactions dated exactly on the checkpoint day', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), 'on the day', now()->subDays(5)->setTime(23, 30, 0));

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint->credit_total)->toEqual(Money::USD(100));
});

it('reopens, corrects, and re-checkpoints with new sums', function () {
    $journal = makeUserJournal();
    $wrong = $journal->credit(Money::USD(10000), 'wrong amount', now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    // Frozen: the bad entry cannot be deleted...
    expect(fn () => $wrong->delete())
        ->toThrow(PeriodClosed::class);

    // ...until the checkpoint is removed.
    $removed = $journal->removeCheckpointsSince(now()->subDays(5));

    expect($removed)->toBe(1);
    expect($journal->fresh()->locked_until)->toBeNull();

    $wrong->delete();
    $journal->credit(Money::USD(9000), 'corrected amount', now()->subDays(10));

    $again = $journal->checkpoint(now()->subDays(5));

    expect($again->credit_total)->toEqual(Money::USD(9000));
    expect($journal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
});

it('resets locked_until to the previous checkpoint after a partial removal', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(20));
    $journal->checkpoint(now()->subDays(10));

    $removed = $journal->removeCheckpointsSince(now()->subDays(10));

    expect($removed)->toBe(1);
    expect($journal->checkpoints()->count())->toBe(1);
    expect($journal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(20)->toDateString());
});

it('removal is inclusive of the given date and returns zero when nothing matches', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(10));

    expect($journal->removeCheckpointsSince(now()->subDays(5)))->toBe(0);
    expect($journal->fresh()->locked_until)->not->toBeNull();

    expect($journal->removeCheckpointsSince(now()->subDays(10)))->toBe(1);
    expect($journal->fresh()->locked_until)->toBeNull();
});

it('enforces the unique journal/date constraint at the database level', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(5));

    $row = DB::table('journal_checkpoints')->first();

    DB::table('journal_checkpoints')->insert([
        'journal_id' => $row->journal_id,
        'checkpoint_date' => $row->checkpoint_date,
        'debit_total' => 0,
        'credit_total' => 0,
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

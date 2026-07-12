<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\Journal;
use Carbon\Carbon;
use Money\Money;

beforeEach(function () {
    Carbon::setTestNow(Carbon::now());
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Post the same transaction set to both journals: three closed-period
 * windows, an open tail, and a future-dated entry.
 */
function postIdenticalHistory(Journal $a, Journal $b): void
{
    foreach ([$a, $b] as $journal) {
        $journal->credit(Money::USD(10000), 'w1 income', now()->subDays(30));
        $journal->debit(Money::USD(2500), 'w1 cost', now()->subDays(28));
        $journal->credit(Money::USD(300), 'w2 income', now()->subDays(15));
        $journal->debit(Money::USD(450), 'w2 cost', now()->subDays(14));
        $journal->credit(Money::USD(77), 'open tail', now()->subDays(2));
        $journal->debit(Money::USD(9), 'future', now()->addDays(7));
    }
}

it('returns identical balances with and without checkpoints', function () {
    $plain = makeUserJournal();
    $checkpointed = Journal::create([
        'currency_code' => 'USD',
        'owner_type' => $plain->owner_type,
        'owner_id' => $plain->owner_id + 1000, // distinct owner id; no owner row needed
    ]);

    postIdenticalHistory($plain, $checkpointed);

    $checkpointed->checkpoint(now()->subDays(20));
    $checkpointed->checkpoint(now()->subDays(10));

    $probes = [
        now()->subDays(25), // between the two windows, before first checkpoint
        now()->subDays(20), // exactly on a checkpoint date
        now()->subDays(12), // between checkpoints
        now()->subDays(1),  // open tail
        now(),              // today
        now()->addDays(30), // beyond the future entry
    ];

    foreach ($probes as $probe) {
        expect($checkpointed->balanceOn($probe))
            ->toEqual($plain->balanceOn($probe));
        expect($checkpointed->debitBalanceOn($probe))
            ->toEqual($plain->debitBalanceOn($probe));
        expect($checkpointed->creditBalanceOn($probe))
            ->toEqual($plain->creditBalanceOn($probe));
    }

    expect($checkpointed->currentBalance())->toEqual($plain->currentBalance());
    expect($checkpointed->totalBalance())->toEqual($plain->totalBalance());
});

it('answers dates before the first checkpoint by summing from zero', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(30));
    $journal->credit(Money::USD(50), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(20));

    expect($journal->balanceOn(now()->subDays(25)))->toEqual(Money::USD(100));
});

it('keeps the cached balance correct when posting after a checkpoint', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $journal->credit(Money::USD(40), null, now());

    expect($journal->fresh()->balance)->toEqual(Money::USD(140));
});

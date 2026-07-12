<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\Exceptions\JournalException;
use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;

it('extends JournalException with a default message', function (string $class, string $messageFragment) {
    $exception = new $class;

    expect($exception)->toBeInstanceOf(JournalException::class);
    expect($exception->getMessage())->toContain($messageFragment);
})->with([
    [JournalAlreadyExists::class, 'already exists'],
    [InvalidJournalEntryValue::class, 'positive value'],
    [InvalidJournalMethod::class, 'credit or debit'],
    [DebitsAndCreditsDoNotEqual::class, 'debits equal credits'],
    [TransactionCouldNotBeProcessed::class, 'could not be processed'],
    [CurrencyMismatch::class, 'currency'],
    [PeriodClosed::class, 'closed'],
    [InvalidCheckpointDate::class, 'after the latest'],
]);

it('appends detail to the unbalanced-group message', function () {
    $exception = new DebitsAndCreditsDoNotEqual('credits == 100 and debits == 99');

    expect($exception->getMessage())
        ->toContain('debits equal credits')
        ->toContain('credits == 100 and debits == 99');
});

it('chains the underlying exception on commit failure', function () {
    $original = new RuntimeException('db went away');
    $exception = new TransactionCouldNotBeProcessed(previous: $original);

    expect($exception->getPrevious())->toBe($original);
});

<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\CheckpointNotRemovable;
use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Exceptions\InvalidJournalModel;
use Academe\LaravelJournal\Exceptions\InvalidLedgerType;
use Academe\LaravelJournal\Exceptions\InvalidTags;
use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\Exceptions\JournalException;
use Academe\LaravelJournal\Exceptions\JournalNotInLedger;
use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;
use Academe\LaravelJournal\Exceptions\TransactionGroupNotFound;
use Money\Currency;

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
    [InvalidCheckpointDate::class, 'after the latest'],
    [JournalNotInLedger::class, 'not assigned to a ledger'],
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

it('appends the cause message to the commit-failure wrapper', function () {
    $exception = new TransactionCouldNotBeProcessed(previous: new RuntimeException('db went away'));

    expect($exception->getMessage())
        ->toBe('Double-entry transaction group could not be processed: db went away');
});

it('keeps an explicit commit-failure message as given', function () {
    $exception = new TransactionCouldNotBeProcessed('custom message', new RuntimeException('cause'));

    expect($exception->getMessage())->toBe('custom message');
});

it('carries the mismatched currencies', function () {
    $exception = new CurrencyMismatch(new Currency('EUR'), new Currency('USD'));

    expect($exception->amountCurrency->getCode())->toBe('EUR');
    expect($exception->journalCurrency->getCode())->toBe('USD');
    expect($exception->getMessage())
        ->toBe('Amount currency EUR does not match journal currency USD.');
});

it('is catchable through the JournalException interface', function () {
    try {
        throw new CheckpointNotRemovable('checkpoint is an opening balance');
    } catch (JournalException $e) {
        expect($e)->toBeInstanceOf(CheckpointNotRemovable::class);
        expect($e->getMessage())->toBe('checkpoint is an opening balance');
    }
});

it('classes developer errors under LogicException', function (string $class) {
    expect(is_a($class, LogicException::class, true))->toBeTrue();
    expect(is_a($class, JournalException::class, true))->toBeTrue();
})->with([
    [InvalidJournalMethod::class],
    [InvalidJournalEntryValue::class],
    [InvalidJournalModel::class],
    [InvalidLedgerType::class],
    [InvalidTags::class],
    [JournalNotInLedger::class],
]);

it('classes runtime conditions under RuntimeException', function (string $class) {
    expect(is_a($class, RuntimeException::class, true))->toBeTrue();
    expect(is_a($class, JournalException::class, true))->toBeTrue();
})->with([
    [JournalAlreadyExists::class],
    [CurrencyMismatch::class],
    [PeriodClosed::class],
    [InvalidCheckpointDate::class],
    [CheckpointNotRemovable::class],
    [TransactionGroupNotFound::class],
    [TransactionCouldNotBeProcessed::class],
    [DebitsAndCreditsDoNotEqual::class],
]);

it('treats an unbalanced group as a commit failure', function () {
    $exception = new DebitsAndCreditsDoNotEqual('credits == 100 and debits == 99');

    expect($exception)->toBeInstanceOf(TransactionCouldNotBeProcessed::class);
    expect($exception->getPrevious())->toBeNull();
    expect($exception->getMessage())
        ->toContain('debits equal credits')
        ->toContain('credits == 100 and debits == 99');
});

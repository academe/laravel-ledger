<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\InvalidJournalModel;
use Academe\LaravelJournal\JournalModels;
use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Models\Ledger;
use Academe\LaravelJournal\Tests\Fixtures\Models\CustomJournal;

it('is registered as a container singleton', function () {
    expect(app(JournalModels::class))
        ->toBeInstanceOf(JournalModels::class)
        ->toBe(app(JournalModels::class));
});

it('returns the package models by default', function () {
    $models = app(JournalModels::class);

    expect($models->ledger())->toBe(Ledger::class)
        ->and($models->journal())->toBe(Journal::class)
        ->and($models->transaction())->toBe(JournalTransaction::class);
});

it('returns a configured override that extends the package model', function () {
    config(['journal.models.journal' => CustomJournal::class]);

    expect(app(JournalModels::class)->journal())->toBe(CustomJournal::class);
});

it('rejects an override that does not extend the package model', function () {
    config(['journal.models.journal' => stdClass::class]);

    app(JournalModels::class)->journal();
})->throws(InvalidJournalModel::class);

it('rejects an override class that does not exist', function () {
    config(['journal.models.ledger' => 'App\\Models\\MissingLedger']);

    app(JournalModels::class)->ledger();
})->throws(InvalidJournalModel::class);

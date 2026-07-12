<?php

use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Models\JournalCheckpoint;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Models\Ledger;

return [
    /*
     * ISO 4217 currency code used when a journal is initialised
     * without an explicit currency.
     */
    'base_currency' => 'GBP',

    /*
     * Override these to substitute your own model classes.
     * Custom classes should extend the package models.
     */
    'models' => [
        'ledger' => Ledger::class,
        'journal' => Journal::class,
        'transaction' => JournalTransaction::class,
        'checkpoint' => JournalCheckpoint::class,
    ],
];

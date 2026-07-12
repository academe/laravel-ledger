<?php

use Academe\LaravelJournal\Enums\StandardLedgerType;
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

    /*
     * Ledger type enums recognised by the ledgers.type column. Each
     * entry is a string-backed enum implementing
     * Academe\LaravelJournal\Contracts\LedgerType; the backing value is
     * the code stored in the database. Append your own enum to extend
     * the standard five accounting elements (contra-accounts, a
     * revenue/gains split, ...). Codes must be unique across all
     * registered enums.
     */
    'ledger_types' => [
        StandardLedgerType::class,
    ],

    /*
     * When to recompute the cached journals.balance column after a
     * transaction is saved or deleted:
     *
     * - 'on_commit' (default): batched. Each affected journal is
     *   recomputed once, just before the outermost database transaction
     *   commits (still inside that transaction). Bulk imports and
     *   transaction groups cost one recompute per journal instead of
     *   one per entry. The cached column is stale while your own
     *   transaction is still open; the computed balance methods are
     *   always accurate.
     *
     * - 'immediate': recomputed synchronously on every save/delete, so
     *   the cached column is never stale, at the cost of one recompute
     *   per entry.
     */
    'balance_update' => 'on_commit',
];

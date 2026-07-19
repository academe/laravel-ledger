# Configuration

[← Back to README](../README.md)

`config/journal.php` (publish with `--tag=journal-config` to customise):

```php
return [
    // ISO 4217 currency code used when a journal is initialised
    // without an explicit currency.
    'base_currency' => 'GBP',

    // Override these to substitute your own model classes.
    // Custom classes should extend the package models.
    'models' => [
        'ledger' => Academe\LaravelJournal\Models\Ledger::class,
        'journal' => Academe\LaravelJournal\Models\Journal::class,
        'transaction' => Academe\LaravelJournal\Models\JournalTransaction::class,
        'checkpoint' => Academe\LaravelJournal\Models\JournalCheckpoint::class,
    ],

    // When to recompute the cached journals.balance column:
    // 'on_commit' (default) batches the recompute — once per journal,
    // just before the surrounding transaction commits; 'immediate'
    // recomputes on every transaction save/delete.
    'balance_update' => 'on_commit',
];
```

Set `models.ledger`, `models.journal`, `models.transaction`, or
`models.checkpoint` to your own subclasses if you need to add scopes, casts,
or relations of your own. See
[When the balance cache is recomputed](#when-the-balance-cache-is-recomputed)
below for the `balance_update` trade-offs.

## When the balance cache is recomputed

`journals.balance` is a cached column kept in sync automatically whenever a
`JournalTransaction` is saved or deleted. The cached value equals
`totalBalance()` (it includes future-dated transactions), not
`currentBalance()`. **When** the cache is recomputed is configurable via
`journal.balance_update`:

- `'on_commit'` (the default): recomputes are batched per journal and run
  just before the surrounding database transaction commits — still inside
  it, so the cache commits atomically with the entries it reflects. A bulk
  import of 100 entries in one transaction costs one recompute, not 100.
  The trade-off: while your own transaction is still open, the cached
  column reads stale; the computed methods (`currentBalance()`,
  `balanceOn()`, `totalBalance()`) are always accurate.
- `'immediate'`: recomputes synchronously on every save/delete, so the
  cached column is never stale inside your own transaction, at the cost
  of one recompute per entry.
- Running under Laravel Octane? Add
  `Academe\LaravelJournal\PendingBalanceUpdates::class` to
  `config('octane.flush')` so the batching state is reset between
  requests. Avoid mutating `$transaction->journal` without saving it
  yourself — the deferred recompute persists that instance.

## Soft deletes

`journal_transactions.deleted_at` exists in the packaged migration so a
custom transaction model may opt into `SoftDeletes`; the packaged
`JournalTransaction` model does not use it.

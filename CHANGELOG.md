# Changelog

## 1.1.0 - Unreleased

### Added
- Period checkpoints: `Journal::checkpoint($date)` stores frozen cumulative
  totals; all balance methods start from the nearest checkpoint instead of
  summing full history.
- Closed periods: transactions dated within a checkpointed range can no
  longer be created, changed, or deleted (`PeriodClosed`).
- Reopening: `Journal::removeCheckpointsSince($date)` (newest-first) to
  correct past periods, then re-checkpoint.
- Ledger bulk operations: `Ledger::checkpoint($date)` and
  `Ledger::removeCheckpointsSince($date)` over member journals.
- `JournalCheckpoint` model, `journal.models.checkpoint` config key,
  `journals.locked_until` column, `PeriodClosed` and
  `InvalidCheckpointDate` exceptions.
- Opening-balance protection: `removeCheckpointsSince()` throws the new
  `CheckpointNotRemovable` rather than delete a checkpoint with
  non-zero totals dated before every transaction in its journal — such
  a checkpoint is a seeded brought-forward starting point whose totals
  cannot be recomputed.
- Opinionated tags: `TagsCast` constrains `journal_transactions.tags`
  to a flat map of string keys and scalar values, throwing the new
  `InvalidTags` on lists, nested arrays, or objects. A stored `NULL`
  reads as `[]` and an empty map is stored as `NULL`. The column is now
  `jsonb` (real `jsonb` on Postgres; unchanged rendering elsewhere).
- Extensible ledger types: `Contracts\LedgerType` (a `BackedEnum` with
  `normalBalance(): BalanceSide`), the `journal.ledger_types` config
  registry, and the `LedgerTypeCast` that resolves stored codes through
  it. Applications can register their own string-backed enums (for
  example contra-accounts) alongside the standard five;
  `Ledger::currentBalance()` now signs its result from
  `normalBalance()` instead of matching concrete enum cases.
  `InvalidLedgerType` is thrown for unknown codes or unregistered
  enums.
- Batched balance-cache updates: the `journal.balance_update` config key
  (default `'on_commit'`) defers the cached `journals.balance` recompute
  until just before the surrounding database transaction commits, so bulk
  postings recompute each journal once instead of once per entry. Set it
  to `'immediate'` for the previous per-write behaviour.
- Journal display naming: opt-in `Contracts\NamesJournal` interface for
  owner models plus `Journal::displayName()` (owner name, else
  `{type} #{owner_id}`, else `journal #{id}`), used wherever the
  package names a journal in messages, and `Journal::description()`
  (the owner's `journalDescription()`, else null).
- `Enums\EntryType` (`Credit`/`Debit`, string-backed as `'credit'` /
  `'debit'`): `TransactionGroup::addTransaction()` now accepts
  `EntryType|string`, normalising strings through the enum — unknown
  strings still throw `InvalidJournalMethod` — and `pending()` entries
  carry the enum in their `method` key.
- Structured exception data: `PeriodClosed` carries `$journal`,
  `$lockedUntil`, and `$postDate` as readonly properties;
  `CurrencyMismatch` carries `$amountCurrency` and `$journalCurrency`.
  `TransactionCouldNotBeProcessed` now appends its cause's message
  (`... could not be processed: Journal "VAT owed" is closed ...`);
  `getPrevious()` remains the structured route.

### Changed

- `CurrencyMismatch::__construct()` now takes the two `Money\Currency`
  values (amount, journal) before the optional message; `PeriodClosed`
  (new in this release) requires its journal and dates. Code that only
  catches these exceptions is unaffected.
- With the default `'on_commit'` mode, the cached `journals.balance`
  column is stale while the writing transaction is still open (the
  computed balance methods remain accurate throughout). Standalone
  postings outside a transaction are unaffected.

## 1.0.0 - Unreleased

Initial release of academe/laravel-journal, a modernised conversion of
[consilience/accounting](https://github.com/consilience/accounting)
(itself a fork of scottlaurent/accounting).

### Added
- Laravel 12 / PHP 8.2+ support.
- Currency validation on posting (`CurrencyMismatch`).
- Indexes on morph, journal, post date, and group columns.
- Pest test suite, PHPStan (level 6), Pint, GitHub Actions CI.
- `TransactionGroup` validates that credits equal debits per currency, so a
  numerically balanced but cross-currency group is rejected with
  `DebitsAndCreditsDoNotEqual`.
- Unique constraint on `journals` (`owner_type`, `owner_id`) so a model
  instance can never end up with more than one journal at the database level.

### Changed
- Package renamed to `academe/laravel-journal`; namespace `Academe\LaravelJournal`.
- Tables renamed: `journal_ledgers`, `journals`, `journal_transactions`.
- Journal morph columns renamed `morphed_*` -> `owner_*`.
- `Services\Accounting` replaced by `TransactionGroup`.
- Traits moved to `Concerns\HasJournal` / `Concerns\HasJournalTransactions`.
- `TransactionCouldNotBeProcessed` now chains the underlying exception.
- Transaction group references are now persisted (fork dropped them silently).

### Removed
- Deprecated `morphed` relation and `referencesObject()` method.
- Empty `Journal::remove()` stub.

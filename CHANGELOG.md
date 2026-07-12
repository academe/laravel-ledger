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

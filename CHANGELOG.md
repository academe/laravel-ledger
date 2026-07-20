# Changelog

## Unreleased

### Added
- `Journal::normalBalanceOn(?CarbonInterface $date = null)`: the journal's
  balance signed from its assigned ledger's normal balance side — an
  accountant-readable single-journal balance without summing the whole
  ledger. Throws the new `JournalNotInLedger` (a `JournalLogicException`)
  when the journal has no ledger to take a normal balance side from.
- `Journal::balanceOn()` now accepts a null date (the default), meaning
  now — so `balanceOn()` equals `currentBalance()`.
- `Ledger::normalBalanceOn(Currency|string $currency, ?CarbonInterface
  $date = null)`: the ledger's normal balance at the end of the given day
  (null means now, excluding future-dated transactions).
- `Ledger::normalTotalBalance($currency)`: the all-time normal balance,
  including future-dated transactions — what `currentBalance()` computed.
- A new [Balances](docs/balances.md) doc page explaining the two sign
  conventions: journal balances are neutral (credit − debit, always);
  ledger balances are normal balances signed from the ledger type.

### Deprecated
- `Ledger::currentBalance()`, renamed `normalTotalBalance()`: every ledger
  balance is a normal balance, and the old name never carried the
  bounded-to-now meaning that `Journal::currentBalance()` has. The alias
  remains until 2.0.

## 1.1.0 - Unreleased

### Added
- `Support\MoneyFormatter`: static helpers converting `Money` values to and
  from strings without the moneyphp boilerplate. `decimal()` /
  `parseDecimal()` handle plain decimal strings with no extension
  requirements; `format()` / `parse()` are locale-aware (currency symbol,
  digit grouping), default to the application locale, and throw a clear
  `RuntimeException` when ext-intl is not loaded. The underlying
  `NumberFormatter` is memoized per locale and style, so formatting a
  report of hundreds of amounts builds it once.
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
- `JournalTransaction::whereGroup($uuid)` scope to fetch the entries
  committed together as one transaction group.
- Group reversal: `TransactionGroup::reverse($uuid, $postDate = null)`
  builds the mirror image of a committed group (credits and debits
  swapped, references kept, memos prefixed `Reversal:`) as a new
  uncommitted group — inspect or extend it, then `commit()`. Throws
  the new `TransactionGroupNotFound` for an unknown UUID. The
  closed-period-safe way to undo a posted group.
- Structured exception data: `PeriodClosed` carries `$journal`,
  `$lockedUntil`, and `$postDate` as readonly properties;
  `CurrencyMismatch` carries `$amountCurrency` and `$journalCurrency`.
  `TransactionCouldNotBeProcessed` now appends its cause's message
  (`... could not be processed: Journal "VAT owed" is closed ...`);
  `getPrevious()` remains the structured route.

### Changed

- Exception hierarchy restructured: `JournalException` is now a marker
  *interface* (extending `Throwable`) — `catch (JournalException $e)`
  behaves as before — with two abstract bases beneath it:
  `JournalLogicException` (`LogicException`; developer errors:
  `InvalidJournalMethod`, `InvalidJournalEntryValue`,
  `InvalidJournalModel`, `InvalidLedgerType`, `InvalidTags`) and
  `JournalRuntimeException` (`RuntimeException`; everything else).
  `DebitsAndCreditsDoNotEqual` now extends
  `TransactionCouldNotBeProcessed`, so catching the wrapper covers
  every way `TransactionGroup::commit()` can fail.
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

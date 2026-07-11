# academe/laravel-journal — Phase 1 Conversion Design

**Date:** 2026-07-11
**Status:** Approved
**Source package:** [consilience/accounting](https://github.com/consilience/accounting) (fork of scottlaurent/accounting)

## Goal

Convert the consilience/accounting fork into a new, modernised package released as
`academe/laravel-journal`. Phase 1 preserves the existing feature set and behaviour
while modernising the platform, resolving the codebase's outstanding `@todo`s, and
renaming everything to a journal-centric identity in a single breaking change.

## Decisions taken

| Decision | Choice |
|---|---|
| Makeover depth | Modernise + clean up (resolve `@todo`s; behaviour preserved) |
| Git history | Fresh repository; old history remains in the fork |
| Minimum PHP | 8.2 (matches Laravel 12 floor) |
| Minimum Laravel | 12 |
| Test framework | Pest (on Orchestra Testbench) |
| Table naming | `journal_*` scheme: `journal_ledgers`, `journals`, `journal_transactions` |
| Upgrade path | Documented rename guide for apps with live `accounting_*` data |
| API naming | Full journal-centric rename in one breaking round |

## 1. Package identity & tooling

- **Composer name:** `academe/laravel-journal`; **namespace:** `Academe\LaravelJournal`, PSR-4 from `src/`.
- **Requires:** PHP `^8.2`, `illuminate/support` + `illuminate/database` `^12.0`, `moneyphp/money` `^4.0`.
- **Dev dependencies:** `orchestra/testbench ^10`, `pestphp/pest ^3`, `larastan/larastan ^3`, `laravel/pint`.
- **CI:** GitHub Actions — Pest matrix over PHP 8.2/8.3/8.4, plus Pint (style) and PHPStan (analysis) jobs.
- MIT licence. Service provider auto-discovered via composer `extra.laravel.providers`.
- Fresh git repository in `laravel-journal/`.

## 2. Components

Renames (old → new):

| Old (`Scottlaurent\Accounting`) | New (`Academe\LaravelJournal`) |
|---|---|
| `Providers\AccountingServiceProvider` | `JournalServiceProvider` |
| `config/accounting.php` | `config/journal.php` |
| `Models\Journal`, `Models\JournalTransaction`, `Models\Ledger` | Same class names, new namespace |
| `ModelTraits\HasAccountingJournal` | `Concerns\HasJournal` |
| `ModelTraits\HasAccountingJournalTransactions` | `Concerns\HasJournalTransactions` |
| `Services\Accounting` | `TransactionGroup` |
| `Casts\MoneyCast`, `Casts\CurrencyCast` | Unchanged names, new namespace |
| `Enums\LedgerType` | Unchanged name, new namespace |
| `Exceptions\BaseException` | `Exceptions\JournalException` |

- `JournalServiceProvider` publishes config and migrations; merges package config.
- `config/journal.php` holds `base_currency` and overridable model classes
  (`models.ledger`, `models.journal`, `models.transaction`).
- `TransactionGroup::make()` replaces `Accounting::newDoubleEntryTransactionGroup()`.

### Schema (breaking, one-time)

- Tables: `journal_ledgers`, `journals`, `journal_transactions`.
- Journal morph columns `morphed_type`/`morphed_id` → `owner_type`/`owner_id`;
  the deprecated `morphed` relation is removed and `owner` remains.
- Indexes added: journal owner morph columns, transaction `journal_id`,
  `post_date`, `transaction_group`, and reference morph columns.
- `JournalTransaction` uses Laravel's `HasUuids` (ordered UUID string key) instead of
  a manual boot-time UUID; `transaction_group` is a UUID column.
- Anonymous migrations, publishable via `vendor:publish`.

### Cleanups folded into phase 1 (behaviour-preserving)

- Currency validation on posting: a `Money` value in a different currency from the
  journal throws `CurrencyMismatch` (previously silently accepted).
- Journal `balance` defaults to zero via a model attribute default
  (`protected $attributes = ['balance' => 0]`) rather than a save-on-created
  model event.
- `initJournal()` takes the ledger id as `?int` (the old signature declared
  `?string` against a bigint column, flagged `@todo` in the fork).
- Deprecated `referencesObject()` removed — use `reference()->associate($model)`.
- Empty `Journal::remove()` stub dropped.
- `Ledger::currentBalance()` reimplemented with SQL aggregate sums and a currency
  filter instead of loading every transaction into memory (same result, faster,
  and no longer mixes currencies).
- README rewritten to match the real API (the old README documents removed
  `creditDollars()`-style methods).

## 3. Data flow / behaviour (unchanged from the fork)

- **Posting:** `$journal->credit($value, $memo, $postDate, $group)` and
  `debit(...)` create a `JournalTransaction` row. Amounts are stored as integer
  minor units, with credit and debit in separate nullable columns. An `int`
  value means minor units in the journal's currency; a `Money` value must match
  the journal's currency or `CurrencyMismatch` is thrown.
- **Balance caching:** `journals.balance` is a cached column recomputed by
  `resetCurrentBalance()` whenever a transaction is saved or deleted. This stays
  synchronous in phase 1; the later period-checkpoint feature builds on it.
  `currentBalance()` computes to now, `balanceOn($date)` to end of a given day,
  `totalBalance()` includes future-dated entries. `debitBalanceOn()` /
  `creditBalanceOn()` give one-sided sums.
- **Double entry:** `TransactionGroup::make()` →
  `addTransaction($journal, 'credit'|'debit', $money, $memo?, $reference?, $postDate?)`
  → `commit()`. Commit asserts credits === debits
  (`DebitsAndCreditsDoNotEqual`), writes all entries inside one DB transaction,
  stamps them with a shared group UUID, and returns that UUID.
- **Ledgers (optional):** a journal may belong to a ledger typed by the
  `LedgerType` enum (asset, liability, equity, income, expense). Asset and
  expense ledgers report balance as debit − credit; the others credit − debit.
- **Journal ownership:** any model using `Concerns\HasJournal` gains
  `journal()` (morphOne) and `initJournal($currency?, $ledgerId?)`, which throws
  `JournalAlreadyExists` on a second call. `Concerns\HasJournalTransactions`
  gives a model a `journalTransactions()` morphMany via the `reference` morph.

## 4. Error handling

All package exceptions extend `Exceptions\JournalException`:

- `JournalAlreadyExists` — `initJournal()` called twice for one model.
- `InvalidJournalEntryValue` — non-positive amount in a transaction group.
- `InvalidJournalMethod` — method other than credit/debit in a group.
- `DebitsAndCreditsDoNotEqual` — unbalanced group at commit.
- `TransactionCouldNotBeProcessed` — commit failed; now constructed with the
  underlying exception as `$previous` instead of flattening it into a message.
- `CurrencyMismatch` (new) — posted `Money` currency differs from the journal's.

## 5. Testing

- Port the four existing PHPUnit test classes to Pest on Testbench 10:
  journal initialisation/posting/balances, ledger balance maths, and the large
  double-entry simulations (~1,000 mixed cash/AR transactions verifying the
  fundamental accounting equation).
- New tests for the phase-1 cleanups: currency mismatch rejection, zero-balance
  default on journal creation, `HasUuids` key generation, exception `$previous`
  chaining, and the SQL-based ledger balance.
- Test support models/migrations (user, account, product, company journal)
  ported into `tests/` fixtures as today.

## 6. Documentation & upgrade path

- `README.md` rewritten against the actual API with the three usage scenarios
  (simple running balance, manual double entry, ledger-enforced double entry).
- `UPGRADE.md` for apps moving from consilience/accounting (e.g. print-trail):
  class/namespace mapping table plus a copyable rename migration
  (`accounting_ledgers` → `journal_ledgers`, `accounting_journals` → `journals`,
  `accounting_journal_transactions` → `journal_transactions`, journal
  `morphed_*` → `owner_*` columns).
- `CHANGELOG.md` starting at 1.0.0.

## 7. Roadmap (out of scope for phase 1)

1. **Model improvements:** rework how tags are stored, add a general-purpose
   morphed relation on `journal_transactions` (point at anything), and other
   model refinements.
2. **Period checkpoints:** end-of-period fixed points storing period totals so
   balance calculations no longer scan the full transaction history.

# Upgrading from scottlaurent/accounting or consilience/accounting

`academe/laravel-journal` is a full rename and modernisation of
[consilience/accounting](https://github.com/consilience/accounting) (itself
a fork of [scottlaurent/accounting](https://github.com/scottlaurent/accounting)).
It is a breaking change: namespaces, class names, config keys, and table
names have all moved in one round, rather than being deprecated gradually.

## 1. Class and namespace mapping

Every public class under `Scottlaurent\Accounting` (or its
`Consilience\Accounting` fork equivalent) moves to `Academe\LaravelJournal`
as follows:

| Old (`Scottlaurent\Accounting\...`) | New (`Academe\LaravelJournal\...`) |
|---|---|
| `Providers\AccountingServiceProvider` | `JournalServiceProvider` |
| `Models\Journal` | `Models\Journal` (same class name, new namespace) |
| `Models\JournalTransaction` | `Models\JournalTransaction` (same class name, new namespace) |
| `Models\Ledger` | `Models\Ledger` (same class name, new namespace) |
| `ModelTraits\HasAccountingJournal` | `Concerns\HasJournal` |
| `ModelTraits\HasAccountingJournalTransactions` | `Concerns\HasJournalTransactions` |
| `Services\Accounting` | `TransactionGroup` |
| `Services\Accounting::newDoubleEntryTransactionGroup()` | `TransactionGroup::make()` |
| `Casts\MoneyCast` | `Casts\MoneyCast` (same class name, new namespace) |
| `Casts\CurrencyCast` | `Casts\CurrencyCast` (same class name, new namespace) |
| `Enums\LedgerType` | `Enums\StandardLedgerType` (implements `Contracts\LedgerType`; same case names and stored values) |
| `Exceptions\BaseException` | `Exceptions\JournalException` (now an *interface* — catch it exactly as before, but extend one of the abstract bases `JournalLogicException` / `JournalRuntimeException` instead) |

All other exception class names (`JournalAlreadyExists`,
`InvalidJournalEntryValue`, `InvalidJournalMethod`,
`DebitsAndCreditsDoNotEqual`, `TransactionCouldNotBeProcessed`) keep the same
name and move under `Academe\LaravelJournal\Exceptions`. `CurrencyMismatch`
is new — see [Behaviour changes](#4-behaviour-changes) below.

Update your `use` statements accordingly, and add `Concerns\HasJournal` /
`Concerns\HasJournalTransactions` to any model that previously used the
`ModelTraits\*` traits.

## 2. Method-by-method mapping

The tables below map every old public method to its new equivalent. "Old"
throughout means the precursor package's API.

### Journal initialisation

| Old | New |
|---|---|
| `initJournal(?string $currencyCode = 'USD', ?string $ledgerId = null)` | `initJournal(Currency\|string\|null $currency = null, ?int $ledgerId = null)` |

Two breaking differences: the default currency is now
`config('journal.base_currency')` — `GBP` out of the box — rather than a
hardcoded `'USD'`, and `$ledgerId` is an `?int` (the ledger's primary key),
not a string.

### Posting to a journal

| Old | New |
|---|---|
| `credit($value, $memo, $postDate, $transactionGroup)` | `credit(Money\|int $value, ?string $memo, ?CarbonInterface $postDate, ?string $transactionGroup)` |
| `debit($value, ...)` | `debit(...)` — same shape as `credit()` |
| `creditDollars(float $amount, ...)` | **removed** — pass a `Money`, or an `int` of minor units |
| `debitDollars(float $amount, ...)` | **removed** — as above |

An `int` value means minor units in the journal's own currency; a `Money`
value must match the journal currency or `CurrencyMismatch` is thrown.

### Balances

| Old | New |
|---|---|
| `getBalance(): Money` | `totalBalance(): Money` — includes future-dated transactions |
| `getCurrentBalance(): Money` | `currentBalance(): Money` — excludes future-dated transactions |
| `getBalanceOn(Carbon $date): Money` | `balanceOn(CarbonInterface $date): Money` |
| `getDebitBalanceOn(Carbon $date): Money` | `debitBalanceOn(CarbonInterface $date): Money` |
| `getCreditBalanceOn(Carbon $date): Money` | `creditBalanceOn(CarbonInterface $date): Money` |
| `getDollarsDebitedToday()` / `getDollarsCreditedToday()` | **removed** — use `debitBalanceOn(now())` / `creditBalanceOn(now())` |
| `getDollarsDebitedOn($date)` / `getDollarsCreditedOn($date)` | **removed** — use `debitBalanceOn($date)` / `creditBalanceOn($date)` |
| `getCurrentBalanceInDollars(): float` / `getBalanceInDollars(): float` | **removed** — see [Replacing the float helpers](#replacing-the-float-helpers) |
| `resetCurrentBalances(): Money` | `resetCurrentBalance(): Money` — singular |

The cached `journals.balance` column holds `totalBalance()` (so it includes
future-dated transactions), and the in-memory model instance is stale
immediately after posting — read `$journal->fresh()->balance` or call one of
the balance methods when you need the up-to-date figure.

### Referencing another model from a transaction

| Old | New |
|---|---|
| `$transaction->referencesObject($product)` | `$transaction->reference()->associate($product)->save()` |
| `$transaction->getReferencedObject(): ?Model` | `$transaction->reference` — a standard `MorphTo` relation |
| `$journal->transactionsReferencingObjectQuery($product)` | read from the referenced side: add `Concerns\HasJournalTransactions` to the model and use `$product->journalTransactions` |

### Ledgers

| Old | New |
|---|---|
| `$journal->assignToLedger($ledger): void` | `assignToLedger(Ledger $ledger): self` — now chainable |
| `$ledger->getCurrentBalance(string $currencyCode): Money` | `$ledger->normalTotalBalance(Currency\|string $currency): Money` — same all-time sum; `normalBalanceOn($currency, $date = null)` for a date-bounded normal balance; `currentBalance()` remains as a deprecated alias |
| `$ledger->getCurrentBalanceInDollars(): float` | **removed** — see [Replacing the float helpers](#replacing-the-float-helpers) |

### Ledger types

The old `Enums\LedgerType` enum had seven cases and two boolean helpers;
`Enums\StandardLedgerType` has five cases and expresses the normal balance
side through the `Contracts\LedgerType` interface instead:

| Old | New |
|---|---|
| `asset`, `liability`, `equity`, `expense` cases | same case names and stored values |
| `revenue` | `income` — same credit-normal semantics, new name and stored value |
| `gain` | fold into `income`, or define a custom type (see below) |
| `loss` | fold into `expense`, or define a custom type |
| `isDebitNormal(): bool` | `normalBalance() === BalanceSide::Debit` |
| `isCreditNormal(): bool` | `normalBalance() === BalanceSide::Credit` |
| `LedgerType::values(): array` | `array_column(StandardLedgerType::cases(), 'value')` |

If your `ledgers.type` column holds stored `revenue`, `gain`, or `loss`
values, either update the rows as part of the data migration
(`revenue` → `income`, `gain` → `income`, `loss` → `expense`), or keep the
distinct values by registering your own string-backed enum implementing
`Contracts\LedgerType` under `config('journal.ledger_types')` — the
[custom ledger types](docs/ledgers.md#custom-ledger-types) section of the
ledgers guide shows a gain/loss example.

### Double-entry transaction groups

| Old | New |
|---|---|
| `Transaction::newDoubleEntryTransactionGroup(): self` | `TransactionGroup::make(): static` |
| `addTransaction(Journal, string $method, Money, $memo, $ref, $postdate): void` | `addTransaction(Journal, EntryType\|string $method, Money, ?string $memo, ?Model $reference, ?CarbonInterface $postDate): static` — chainable |
| `addDollarTransaction(Journal, string, float\|int\|string, ...)` | **removed** — build a `Money` and use `addTransaction()` |
| `getTransactionsPending(): array` | `pending(): array` |
| `commit(): string` | `commit(): string` — returns the shared group UUID |
| *(no equivalent)* | `TransactionGroup::reverse(string $group, ?CarbonInterface $postDate = null): static` — posts the mirror-image of a committed group |

`$method` accepts the `EntryType` enum (`EntryType::Credit` /
`EntryType::Debit`); the legacy `'credit'` / `'debit'` strings still work.

### Replacing the float helpers

Every float-returning `*Dollars` / `*InDollars` method is removed with no
direct replacement, deliberately: converting to `float` reintroduces exactly
the rounding errors that integer-based `Money` arithmetic exists to prevent.
All amounts in and out of the package are `Money\Money` values.

For display and input, `Academe\LaravelJournal\Support\MoneyFormatter` wraps
the moneyphp formatter/parser boilerplate:

```php
use Academe\LaravelJournal\Support\MoneyFormatter;

MoneyFormatter::decimal($journal->currentBalance());   // "1234.56"
MoneyFormatter::format($journal->currentBalance());    // "£1,234.56" (needs ext-intl)
MoneyFormatter::parseDecimal('1234.56', 'GBP');        // Money::GBP(123456)
MoneyFormatter::parse('£1,234.56', 'GBP');             // Money::GBP(123456) (needs ext-intl)
```

If you genuinely need a raw number, `->getAmount()` on any `Money` returns
the minor-unit amount as a string.

## 3. Configuration

The config file is renamed and its keys change shape:

| Old (`config/accounting.php`) | New (`config/journal.php`) |
|---|---|
| File name: `accounting.php` | File name: `journal.php` |
| `model-classes.ledger` | `models.ledger` |
| `model-classes.journal` | `models.journal` |
| `model-classes.journal-transaction` | `models.transaction` |

Publish the new config and merge across any customisation you had:

```bash
php artisan vendor:publish --tag=journal-config
```

## 4. Behaviour changes

A handful of behaviours changed deliberately as part of the rename — not
just names:

- **Currency is now validated on posting.** If you post a `Money` value
  whose currency doesn't match the journal's currency, `credit()` / `debit()`
  now throw `CurrencyMismatch`. Previously this was accepted silently. Plain
  `int` values are unaffected — they're always minor units in the journal's
  own currency.
- **Transaction group references are now persisted.** In the fork, calling
  `addTransaction()` with a `$reference` model queued the reference but
  never actually saved it against the committed `JournalTransaction` — the
  association was built in memory and discarded. `TransactionGroup::commit()`
  now calls `$transaction->reference()->associate($reference)->save()` for
  every entry that was given a reference, so it's persisted like any
  transaction created via `associate($model)->save()` directly. If your
  application queued references and depended on them being silently
  dropped, you'll now find them saved — this is a bug fix, but check for
  code that relied on the old, broken behaviour.
- **Transaction groups must now balance per currency.** The fork compared
  raw credit and debit totals regardless of currency, so a group of
  `credit USD 100` + `debit EUR 100` committed silently. `commit()` now
  requires credits to equal debits for *each* currency in the group and
  throws `DebitsAndCreditsDoNotEqual` otherwise. Single-currency groups are
  unaffected.
- **`TransactionCouldNotBeProcessed` now carries the original exception.**
  It's constructed with `$previous`, so `getPrevious()` returns the
  underlying failure instead of only a flattened message string.
- The deprecated `morphed` relation and `referencesObject()` method are
  removed; use `reference()->associate($model)` instead.
- The empty `Journal::remove()` stub is gone.

## 5. Data migration

The package's own published migrations (`--tag=journal-migrations`) `CREATE`
fresh tables — run them only on a **new** installation with no existing
`accounting_*` data.

If you have live data in the old `accounting_ledgers` / `accounting_journals`
/ `accounting_journal_transactions` tables, use a rename migration instead.
Copy this into a new migration file and run it in place of the package
migrations:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('accounting_ledgers', 'journal_ledgers');
        Schema::rename('accounting_journals', 'journals');
        Schema::rename('accounting_journal_transactions', 'journal_transactions');

        Schema::table('journals', function ($table) {
            $table->renameColumn('morphed_type', 'owner_type');
            $table->renameColumn('morphed_id', 'owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function ($table) {
            $table->renameColumn('owner_type', 'morphed_type');
            $table->renameColumn('owner_id', 'morphed_id');
        });

        Schema::rename('journal_transactions', 'accounting_journal_transactions');
        Schema::rename('journals', 'accounting_journals');
        Schema::rename('journal_ledgers', 'accounting_ledgers');
    }
};
```

After running the rename, add the indexes the new schema expects but the
old one didn't have — the package migrations index `post_date`,
`transaction_group`, `journal_id`, and both columns of each morph pair
(`owner_type`/`owner_id` on `journals`, `reference_type`/`reference_id` on
`journal_transactions`):

```php
Schema::table('journals', function ($table) {
    // The new schema enforces one journal per owner. Before adding this,
    // check for duplicate (owner_type, owner_id) rows and merge or remove
    // them — the constraint will fail to apply while duplicates exist.
    $table->unique(['owner_type', 'owner_id']);
});

Schema::table('journal_transactions', function ($table) {
    $table->index('journal_id');
    $table->index('post_date');
    $table->index('transaction_group');
    $table->index(['reference_type', 'reference_id']);
});
```

Only run the missing-index migration for indexes your existing tables don't
already have — check your current schema first to avoid a duplicate-index
error.

## 6. Upgrading within academe/laravel-journal: 1.0 → 1.1

This section is for existing `academe/laravel-journal` 1.0 users, not for the
rename above. Version 1.1 adds period checkpoints — see the
[Checkpoints guide](docs/checkpoints.md) for the full feature. The upgrade is almost purely additive:
no namespaces or class names change, and there is **zero behaviour change**
until you call `checkpoint()` for the first time. The one signature change:

- `CurrencyMismatch::__construct()` now takes
  `(Currency $amountCurrency, Currency $journalCurrency, ?string $message = null)`.
  Only code constructing the exception itself is affected; catching and
  reading `getMessage()` behaves as before, and the two currencies are
  now available as public readonly properties.

What's new, in one migration: a `journal_checkpoints` table, and a nullable
`locked_until` column added to the existing `journals` table.

To upgrade:

1. Republish the package migrations:

   ```bash
   php artisan vendor:publish --tag=journal-migrations
   ```

   This only copies migration files that aren't already present in your
   `database/migrations` directory, so it won't duplicate or overwrite the
   migrations you published for 1.0. If you'd rather not republish, copy
   `vendor/academe/laravel-journal/database/migrations/2026_07_12_000000_create_journal_checkpoints_table.php`
   into your own `database/migrations` directory by hand instead.
2. Run `php artisan migrate`.
3. If you use custom model classes via `config/journal.php`, add a
   `models.checkpoint` entry for your own `JournalCheckpoint` subclass —
   it defaults to `Academe\LaravelJournal\Models\JournalCheckpoint`, the same
   pattern as the existing `models.ledger` / `models.journal` /
   `models.transaction` keys.

Nothing else is required. Existing balance methods, `TransactionGroup`, and
ledgers behave exactly as they did on 1.0 until you start calling
`Journal::checkpoint()` or `Ledger::checkpoint()` — at which point the
journals you checkpoint become subject to the closed-period rules described
in the README.

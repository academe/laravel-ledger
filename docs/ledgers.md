# Ledgers

[← Back to README](../README.md)

Journals can optionally be grouped under a `Ledger`, typed by the
`StandardLedgerType` enum (`asset`, `liability`, `equity`, `income`,
`expense`) — the five elements of the accounting equation, universal across
UK GAAP, IFRS, and US GAAP. Each type declares its normal balance side
(`Contracts\LedgerType::normalBalance()`), which is all the balance
arithmetic depends on; you can register your own string-backed enums in the
`journal.ledger_types` config array to add types such as contra-accounts
(see [Custom ledger types](#custom-ledger-types) below).
Ledgers aren't required — plenty of use cases only need a single journal's
running balance. Three common scenarios, from simplest to most rigorous:

## A. Simple running balance per model

Just use `HasJournal` and post directly — no ledger involved:

```php
class Wallet extends Model
{
    use HasJournal;
}

$wallet->initJournal('USD');
$wallet->journal->credit(Money::USD(2000), 'Top up');
$wallet->journal->debit(Money::USD(500), 'Purchase');

$wallet->journal->currentBalance(); // Money::USD(1500)
```

## B. Manual double entry between journals

Post matching credits and debits to two journals yourself, or use
[`TransactionGroup`](double-entry.md) so they're atomic and validated:

```php
$cashJournal = CompanyAccount::create(['name' => 'Cash'])->initJournal('USD');
$arJournal = CompanyAccount::create(['name' => 'Accounts Receivable'])->initJournal('USD');

TransactionGroup::make()
    ->addTransaction($cashJournal, 'debit', Money::USD(10000))
    ->addTransaction($arJournal, 'credit', Money::USD(10000))
    ->commit();
```

## C. Ledger-enforced double entry

Assign journals to typed ledgers, and `Ledger::normalBalanceOn()` gives you
a SQL-aggregated [normal balance](balances.md) across every journal in that
ledger. Debit-normal types (assets, expenses) report **debit − credit**;
credit-normal types (liabilities, equity, income) report **credit − debit**
— so, kept balanced, total assets always equal total liabilities plus
equity plus income minus expenses:

```php
use Academe\LaravelJournal\Enums\StandardLedgerType;
use Academe\LaravelJournal\Models\Ledger;

$assetsLedger = Ledger::create(['name' => 'Assets', 'type' => StandardLedgerType::ASSET]);
$incomeLedger = Ledger::create(['name' => 'Income', 'type' => StandardLedgerType::INCOME]);

$cashJournal = CompanyAccount::create(['name' => 'Cash'])
    ->initJournal('USD')
    ->assignToLedger($assetsLedger);

$salesJournal = CompanyAccount::create(['name' => 'Sales'])
    ->initJournal('USD')
    ->assignToLedger($incomeLedger);

TransactionGroup::make()
    ->addTransaction($cashJournal, 'debit', Money::USD(9900))
    ->addTransaction($salesJournal, 'credit', Money::USD(9900))
    ->commit();

$assetsLedger->normalBalanceOn('USD'); // Money::USD(9900)
$incomeLedger->normalBalanceOn('USD'); // Money::USD(9900)
```

`normalBalanceOn()` takes an optional second date argument (balance at the
end of that day; null means now). `normalTotalBalance()` sums *all*
transactions, including future-dated ones. Both accept either a currency
code string or a `Money\Currency` instance, and only sum transactions in
that currency — mixed-currency journals under the same ledger are kept
separate. See [Balances](balances.md) for the full picture, including the
sign conventions.

## Custom ledger types

`journal.ledger_types` is a registry of enum classes, not a single class to
swap out. To add types beyond the standard five, define a string-backed
enum implementing `Academe\LaravelJournal\Contracts\LedgerType` — its one
method, `normalBalance()`, is all the balance arithmetic depends on:

```php
use Academe\LaravelJournal\Contracts\LedgerType;
use Academe\LaravelJournal\Enums\BalanceSide;

enum ContraAssetType: string implements LedgerType
{
    case CONTRA_ASSET = 'contra-asset';

    public function normalBalance(): BalanceSide
    {
        return BalanceSide::Credit; // asset-side account, credit-normal
    }
}
```

Then append it to the registry in `config/journal.php` (publish with
`--tag=journal-config` if you haven't already):

```php
'ledger_types' => [
    Academe\LaravelJournal\Enums\StandardLedgerType::class,
    App\Enums\ContraAssetType::class,
],
```

From there it behaves like any standard type — `Ledger::create(['name' =>
'Accumulated Depreciation', 'type' => ContraAssetType::CONTRA_ASSET])` —
and the ledger balance methods sign their results from `normalBalance()`
with no further special-casing.

The same mechanism covers charts of accounts that keep gains and losses
distinct from operating income and expenses. `StandardLedgerType` folds
gains into `income` (credit-normal) and losses into `expense`
(debit-normal); if you want them as first-class types instead, register:

```php
enum GainLossType: string implements LedgerType
{
    case GAIN = 'gain';
    case LOSS = 'loss';

    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::GAIN => BalanceSide::Credit,
            self::LOSS => BalanceSide::Debit,
        };
    }
}
```

Things to know:

- The enum's **backing value is the code stored** in `journal_ledgers.type`
  — a plain string column capped at 30 characters, so keep codes within
  that.
- **Codes must be unique across every registered enum.** On read, the
  first registered enum that defines the stored code wins.
- The registry is enforced in both directions: assigning a case from an
  unregistered enum throws `InvalidLedgerType` at write time, and reading
  a row whose stored code no registered enum defines throws it too.
- **Don't strand stored codes.** Every code already stored in
  `journal_ledgers.type` must resolve through some registered enum, or
  reading those rows throws `InvalidLedgerType` — so don't drop
  `StandardLedgerType` from the array while standard-typed rows exist.
- **Replacing `StandardLedgerType` entirely is supported.** Nothing in the
  package references the standard enum concretely — behaviour flows through
  the `LedgerType` interface — so a registry containing only your own enum
  is fine, provided it defines every stored code (redeclare the standard
  backing values, or migrate the column first) and keeps `normalBalance()`
  semantically honest for any code it inherits: redefining `asset` as
  credit-normal silently flips the sign of every existing asset ledger's
  balance. One enum owning all your types is also the clean way to add
  app-level methods such as `label()`, since PHP enums are final and can't
  be extended.

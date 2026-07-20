# Balances

[← Back to README](../README.md)

Balances exist at two levels — the journal and the ledger — and the two
levels follow different sign conventions on purpose. This page explains
how each balance is calculated and how to move between the two views.

## Journal balances: credit − debit, always

A journal stores each entry as unsigned magnitudes in separate `credit`
and `debit` columns — `credit()` and `debit()` take the absolute value of
whatever they are given, so no sign is ever baked into a row. Sign appears
only when the data is read back, and at the journal level the convention
is always the same:

> **Credits are positive, debits are negative.**

Every journal balance method returns the signed sum **credit − debit**,
with no account-type meaning attached. `Academe\LaravelJournal\Models\Journal`
exposes:

| Method | Meaning |
| --- | --- |
| `currentBalance()` | Balance as of now, excluding future-dated transactions. |
| `balanceOn($date = null)` | Balance at the end of the given day (a `CarbonInterface`); null means now, so `balanceOn()` equals `currentBalance()`. |
| `totalBalance()` | Balance across *all* transactions, including future-dated ones. |
| `debitBalanceOn($date)` | Debit-only sum at the end of the given day. |
| `creditBalanceOn($date)` | Credit-only sum at the end of the given day. |
| `normalBalanceOn($date = null)` | The one exception to neutrality: the balance signed from the assigned ledger's normal balance side — see below. |

All of these return a `Money\Money` instance in the journal's currency,
and — apart from `normalBalanceOn()`, which exists precisely to cross
the two conventions — none of them look at the ledger the journal may
be assigned to: assigning a journal to a ledger changes nothing about
what the journal itself reports. The cached `journals.balance` column follows the same
convention (it mirrors `totalBalance()`), as does the signed
`JournalTransaction::$amount` accessor on individual entries.

On a journal with a lot of history, [checkpoint](checkpoints.md)
periodically so these queries scan only what's posted since the last
checkpoint instead of the full history.

## Ledger balances: the normal-balance flip

Ledger balances are always **normal balances** — accountant-compatible,
and named accordingly. Each method sums the debits and credits of every
transaction in the given currency across the ledger's journals, then
signs the result from the ledger type's normal balance side
(`LedgerType::normalBalance()`):

- **Debit-normal** types (asset, expense) report **debit − credit**.
- **Credit-normal** types (liability, equity, income) report
  **credit − debit**.

| Method | Meaning |
| --- | --- |
| `normalBalanceOn($currency, $date = null)` | Normal balance at the end of the given day; null means now, excluding future-dated transactions. |
| `normalTotalBalance($currency)` | Normal balance across *all* transactions, including future-dated ones. |
| `currentBalance($currency)` | **Deprecated** alias of `normalTotalBalance()`; despite the name it was never bounded to now. |

Kept balanced by double entry, every ledger then reads positive, and
assets = liabilities + equity + income − expenses. See
[Ledgers](ledgers.md) for the type system and usage scenarios.

Unlike the journal methods, the ledger sums do not use
[checkpoints](checkpoints.md) — they always scan the transaction rows,
which is the read that can never disagree with the data.

### Same rows, two views

```php
$group->addTransaction($cashJournal, 'debit', Money::USD(9900))
    ->addTransaction($salesJournal, 'credit', Money::USD(9900))
    ->commit();

$cashJournal->totalBalance();            // -9900  (credit − debit, neutral)
$salesJournal->totalBalance();           //  9900

$assetsLedger->normalBalanceOn('USD');   //  9900  (debit-normal: debit − credit)
$incomeLedger->normalBalanceOn('USD');   //  9900  (credit-normal: credit − debit)
```

The cash journal alone reads negative because the neutral convention
treats debits as negative; the asset ledger it belongs to reinterprets
the same rows through its normal balance side and reads positive. Neither
number is wrong — they are two views of identical data.

## An accountant-style balance for a single journal

`Journal::normalBalanceOn()` reads one journal the way an accountant
would, without summing the whole ledger: it takes the neutral
`balanceOn()` result and signs it from the assigned ledger's normal
balance side ("normal balance" is the accounting term for the side —
debit or credit — an account's balance is expected to sit on).

```php
$cashJournal->balanceOn();        // -9900  (neutral: credit − debit)
$cashJournal->normalBalanceOn();  //  9900  (asset, debit-normal: debit − credit)

$salesJournal->normalBalanceOn(); //  9900  (income, credit-normal: unchanged)

$cashJournal->normalBalanceOn(Carbon::parse('2026-06-30')); // as of a date
```

A journal in a credit-normal ledger (liability, equity, income) is
already accountant-readable as-is; only journals in debit-normal ledgers
(asset, expense) are negated. The date argument behaves exactly as in
`balanceOn()`: end of the given day, or now when null.

The journal must be assigned to a ledger — there is no normal balance
side without a ledger type — otherwise `JournalNotInLedger` is thrown
(see [Exceptions](exceptions.md)).

## The cached balance is stale after posting

After posting, the in-memory `$journal` instance's `balance` property is
stale — the recompute happens on the model that was fetched from the
database inside the posting call, not on the instance you're holding.
Call `$journal->fresh()->balance`, or use `currentBalance()` /
`totalBalance()` / `balanceOn()`, to get an up-to-date value.

For turning the returned `Money` values into display strings (and back),
see [Formatting and parsing amounts](money-formatting.md).

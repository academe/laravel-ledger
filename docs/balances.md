# Balances

[← Back to README](../README.md)

After posting, the in-memory `$journal` instance's `balance` property is
stale — the recompute happens on the model that was fetched from the
database inside the posting call, not on the instance you're holding.
Call `$journal->fresh()->balance`, or use `currentBalance()` /
`totalBalance()` / `balanceOn()`, to get an up-to-date value.

`Academe\LaravelJournal\Models\Journal` exposes:

| Method | Meaning |
| --- | --- |
| `currentBalance()` | Balance as of now, excluding future-dated transactions. |
| `balanceOn($date)` | Balance at the end of the given day (a `CarbonInterface`). |
| `totalBalance()` | Balance across *all* transactions, including future-dated ones. |
| `debitBalanceOn($date)` | Debit-only sum at the end of the given day. |
| `creditBalanceOn($date)` | Credit-only sum at the end of the given day. |

All of these return a `Money\Money` instance in the journal's currency. On a
journal with a lot of history, [checkpoint](checkpoints.md)
periodically so these queries scan only what's posted since the last
checkpoint instead of the full history.

For turning the returned `Money` values into display strings (and back),
see [Formatting and parsing amounts](money-formatting.md).

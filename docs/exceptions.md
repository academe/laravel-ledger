# Exceptions

[← Back to README](../README.md)

Everything the package throws deliberately lives under
`Academe\LaravelJournal\Exceptions` and implements the `JournalException`
interface, so a single `catch (JournalException $e)` covers the whole
package (interfaces work in `catch` exactly like parent classes —
`Throwable` itself is one).

Beneath the interface, exceptions split along PHP's own
`LogicException` / `RuntimeException` line:

- **`JournalLogicException`** — developer errors: wrong code or
  configuration. These indicate a bug to fix, not a condition to catch.
- **`JournalRuntimeException`** — conditions a correctly-written
  application can still hit, and may want to handle.

`DebitsAndCreditsDoNotEqual` sits under
`TransactionCouldNotBeProcessed`: an unbalanced group is one kind of
commit failure, so catching the wrapper covers every way `commit()` can
fail.

```text
Throwable (PHP)
└── JournalException (interface)
    ├── JournalLogicException (abstract, extends LogicException)
    │   ├── InvalidJournalMethod
    │   ├── InvalidJournalEntryValue
    │   ├── InvalidJournalModel
    │   ├── InvalidLedgerType
    │   └── InvalidTags
    └── JournalRuntimeException (abstract, extends RuntimeException)
        ├── JournalAlreadyExists
        ├── CurrencyMismatch
        ├── PeriodClosed
        ├── InvalidCheckpointDate
        ├── CheckpointNotRemovable
        ├── TransactionGroupNotFound
        └── TransactionCouldNotBeProcessed
            └── DebitsAndCreditsDoNotEqual
```

| Exception | Thrown when | Carries |
| --- | --- | --- |
| `JournalAlreadyExists` | `initJournal()` is called on a model that already has a journal | |
| `CurrencyMismatch` | a posted `Money` value's currency differs from the journal's (direct `credit()`/`debit()` or inside a group commit) | `$amountCurrency`, `$journalCurrency` |
| `InvalidJournalMethod` | `addTransaction()` is given a method string other than `'credit'` or `'debit'` | |
| `InvalidJournalEntryValue` | `addTransaction()` is given a zero or negative amount | |
| `DebitsAndCreditsDoNotEqual` | `commit()` is called on a group whose credits and debits don't balance within some currency | |
| `TransactionCouldNotBeProcessed` | anything fails inside `commit()`; the whole group has rolled back | the cause, via `getPrevious()` and appended to the message |
| `TransactionGroupNotFound` | `TransactionGroup::reverse()` is given a UUID with no entries | `$transactionGroup` |
| `PeriodClosed` | a transaction dated in a checkpointed period is created, changed, or deleted | `$journal`, `$lockedUntil`, `$postDate` |
| `InvalidCheckpointDate` | `checkpoint()` is dated on or before the journal's latest checkpoint | |
| `CheckpointNotRemovable` | `removeCheckpointsSince()` would delete an opening-balance checkpoint | |
| `InvalidLedgerType` | a stored ledger type code matches no registered enum, or a ledger is given a case from an unregistered enum | |
| `InvalidTags` | transaction tags are not a flat map of string keys to scalar values | |
| `InvalidJournalModel` | a `models.*` config override does not extend the package model it replaces | |

Where an exception carries structured properties (all public and
readonly), read those rather than parsing the message — messages are for
humans and may change wording between releases. `PeriodClosed` messages
name the journal through its owner (see
[Naming journals in messages](checkpoints.md#naming-journals-in-messages)).

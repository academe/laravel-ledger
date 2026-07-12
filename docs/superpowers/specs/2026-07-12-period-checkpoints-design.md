# academe/laravel-journal — Phase 3: Period Checkpoints Design

**Date:** 2026-07-12
**Status:** Approved (design sections approved in conversation; spec pending user review)
**Depends on:** Phase 1 (complete), Phase 2 `JournalModels` accessor (in flight — this spec
assumes `JournalModels` exists and gains a `checkpoint()` method)

## Goal

Balance calculations currently sum every transaction over all time, so they slow
down as journals grow. This phase adds **checkpoints**: frozen per-journal balance
fixed points that balance queries start from instead of zero, and that close the
books for the period they cover.

## Accounting rationale

- Mirrors standard practice: period-end closing / lock dates (as in Xero and
  QuickBooks). The package never knows the period calendar — it only honours the
  dates it is given.
- A checkpoint is a brought-forward balance. Trustworthiness requires the past to
  be immutable, which is also what auditability wants: corrections to closed
  periods are made by adjusting entries in the open period, never by editing
  history.
- Lock dates may trail arbitrarily far behind today. The motivating use-case
  imports royalty payments up to 12 months late, so checkpoints will typically be
  created ~13 months behind the current date, only once data for the range is
  known to be complete.

## Decisions taken

| Decision | Choice |
|---|---|
| Approach | Checkpoint table + Eloquent model-event freeze guards (approach A) |
| Checkpoint home | Per-journal (primary primitive); ledger operations are bulk conveniences over member journals; ledger-less use is fully supported |
| History | A series of checkpoints per journal, kept over time |
| Removal | Newest-first only, via `removeCheckpointsSince($date)` — reopen, correct, re-checkpoint |
| Enforcement | Model events throw `PeriodClosed`; raw `DB::` writes bypass guards (documented limitation, consistent with the balance cache) |
| Lock lookup | Denormalised nullable `journals.locked_until` date column |
| DB triggers | Rejected — not portable, hard to ship/test from a package |
| Non-frozen (invalidatable) snapshots | Rejected — more code for a weaker guarantee |

## 1. Schema

### New table `journal_checkpoints`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint auto PK | |
| `created_at` / `updated_at` | timestamps | |
| `journal_id` | FK → `journals`, indexed | cascade behaviour: restrict (checkpoints must be removed explicitly) |
| `checkpoint_date` | date | totals cover all transactions with `post_date` ≤ **end of this day**, matching `balanceOn()` semantics |
| `debit_total` | bigint | cumulative minor units from the beginning of time through `checkpoint_date` |
| `credit_total` | bigint | ditto; both sides stored so `debitBalanceOn()` / `creditBalanceOn()` accelerate too |
| `currency_code` | char(3) | copied from the journal at creation; makes rows self-describing |

Constraint: `unique(journal_id, checkpoint_date)`.

### Change to `journals`

- Add nullable `locked_until` (date) = the journal's latest `checkpoint_date`,
  maintained inside the same DB transaction as every checkpoint add/remove. The
  freeze guard reads it from the already-loaded journal row with no extra query.

Both changes ship as one new publishable migration (additive; no changes to
existing tables' data).

## 2. Models and API

### `JournalCheckpoint` model

- Table `journal_checkpoints`; `$guarded = ['id']`; casts: `checkpoint_date` →
  date, `currency` → `CurrencyCast:currency_code`, `debit_total` / `credit_total`
  → `MoneyCast` with `currency_code`.
- Config-overridable as `journal.models.checkpoint`; resolved through
  `JournalModels::checkpoint()`.
- Relations: `journal(): BelongsTo`.

### `Journal` additions

- `checkpoints(): HasMany` and `latestCheckpoint(): ?JournalCheckpoint`.
- `checkpoint(CarbonInterface|string $date): JournalCheckpoint`
  - Throws `InvalidCheckpointDate` unless `$date` is strictly after the current
    latest checkpoint date (if any).
  - Computes totals **incrementally**: previous checkpoint's totals + SQL sums of
    transactions with `post_date` in `(end of previous checkpoint_date, end of
    $date]`, filtered by the journal's `currency_code`. No previous checkpoint →
    sums from the beginning.
  - Inserts the row and updates `journals.locked_until`, all inside one DB
    transaction so a concurrent posting cannot slip between the sum and the
    freeze.
- `removeCheckpointsSince(CarbonInterface|string $date): int`
  - Deletes all of the journal's checkpoints with `checkpoint_date` ≥ `$date`;
    resets `locked_until` to the new latest checkpoint date, or null if none
    remain; returns the number removed. Runs in one DB transaction.
  - The "since" shape enforces newest-first removal — there is deliberately no
    API to delete a mid-series checkpoint (it would not unlock anything and
    would corrupt the incremental chain).
- Reopening workflow (documented): `removeCheckpointsSince($d)` → post
  corrections → `checkpoint(...)` again, each new checkpoint recomputing fresh
  sums from its predecessor.

### `Ledger` additions (bulk conveniences only — no ledger checkpoint storage)

- `checkpoint(CarbonInterface|string $date): int` — calls
  `Journal::checkpoint($date)` on every member journal inside one DB
  transaction; returns the number of journals checkpointed. If any member
  journal fails (e.g. `InvalidCheckpointDate`), the whole operation rolls back.
- `removeCheckpointsSince(CarbonInterface|string $date): int` — bulk removal
  across member journals in one DB transaction; returns checkpoints removed.

### Date semantics

- A checkpoint date may be any date, past or future. A future-dated checkpoint
  freezes all posting until removed — allowed, documented as the application's
  responsibility. Different journals may have different lock dates; a
  `TransactionGroup` touching journals with mixed lock dates either commits
  fully or rolls back atomically, so no torn writes are possible.

## 3. Balance-method integration (transparent)

No public API changes; every balance method starts from the nearest checkpoint:

- `debitBalanceOn($date)` / `creditBalanceOn($date)`: latest checkpoint with
  `checkpoint_date` ≤ `$date` (if any) supplies the starting total; add the SQL
  sum of transactions with `post_date` > end of checkpoint day and ≤ end of
  `$date`. No checkpoint ≤ `$date` → current behaviour (sum from zero).
- `balanceOn($date)` and `currentBalance()` derive from those two, unchanged.
- `totalBalance()`: latest checkpoint (any date) + sum of all transactions after
  it, including future-dated ones — same semantics as today.
- `resetCurrentBalance()` / cached `journals.balance`: semantics unchanged; the
  recompute that runs on every transaction save/delete becomes cheap because it
  starts from the latest checkpoint.

Correctness property (pinned by tests): for identical data, every balance method
returns the same `Money` with checkpoints present as without.

## 4. Freeze enforcement

Guards in `JournalTransaction` model events; all throw `PeriodClosed`:

- **creating** — new `post_date` ≤ journal's `locked_until` (end of day).
- **updating** — original `post_date` frozen (no editing frozen entries) OR new
  `post_date` frozen (no moving entries into a frozen period).
- **deleting** — original `post_date` frozen.

Notes:

- `TransactionGroup::commit()` needs no changes: a frozen entry throws
  mid-commit, the surrounding DB transaction rolls back, and the caller receives
  `TransactionCouldNotBeProcessed` with `PeriodClosed` as `getPrevious()`.
- The remedy for needing to change a closed period is an adjusting entry in the
  open period, or the reopening workflow above.
- Raw `DB::` writes bypass Eloquent events and therefore the guards — documented
  limitation, identical in kind to the existing cached-balance behaviour.

## 5. Errors

Two new exceptions extending `JournalException`:

- `PeriodClosed` — message names the journal id, the offending `post_date`, and
  the journal's `locked_until`.
- `InvalidCheckpointDate` — thrown when a checkpoint date is not strictly after
  the journal's current latest checkpoint date.

## 6. Configuration

- `journal.models.checkpoint` added to `config/journal.php`, defaulting to
  `Academe\LaravelJournal\Models\JournalCheckpoint`.
- `JournalModels` gains `checkpoint(): class-string<JournalCheckpoint>`.

## 7. Testing

- **Equivalence property**: balances identical with and without checkpoints —
  across `balanceOn`, `currentBalance`, `totalBalance`, `debitBalanceOn`,
  `creditBalanceOn`; including a multi-checkpoint series and future-dated
  transactions straddling the latest checkpoint.
- **Incremental correctness**: a second checkpoint built on a first equals a
  from-scratch computation of the same range.
- **Freeze guard**: create/update/delete rejected at and before `locked_until`,
  allowed after; moving a transaction into a frozen period rejected.
- **Group atomicity**: balanced group with one frozen-period entry →
  `TransactionCouldNotBeProcessed`, `getPrevious()` instanceof `PeriodClosed`,
  zero rows written, cached balances rolled back.
- **Reopen cycle**: checkpoint → removeCheckpointsSince → correct a transaction
  → re-checkpoint → new sums reflect the correction; `locked_until` correct at
  each step.
- **Validation**: `InvalidCheckpointDate` on non-increasing dates; unique
  `(journal_id, checkpoint_date)` enforced.
- **Ledger bulk ops**: all member journals checkpointed atomically; failure of
  one member rolls back all.
- **Ledger-less**: journals with no ledger checkpoint and freeze normally.

## 8. Documentation & versioning

- README: new "Speeding up balances with checkpoints" and "Closing a period"
  sections (checkpoint/remove API, reopening workflow, adjusting-entry guidance,
  raw-write limitation, future-dated checkpoint warning).
- UPGRADE note: additive migration only (new table + nullable column); no
  behaviour change for apps that never create a checkpoint.
- CHANGELOG: 1.1.0.

## Out of scope (explicitly)

- Ledger-level checkpoint storage/rollup rows (derivable; add later only if
  profiling demands).
- Database triggers.
- Archiving/pruning frozen transactions (enabled by this design; not built).
- Phase 2 model improvements (tags storage, general-purpose transaction morphs)
  — separate spec/plan.

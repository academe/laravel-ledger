# Period Checkpoints Implementation Plan (Phase 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add frozen per-journal balance checkpoints so balance calculations start from stored totals instead of summing every transaction, with model-event guards that close the checkpointed period.

**Architecture:** A `journal_checkpoints` table stores cumulative debit/credit totals per journal per date, built incrementally from the previous checkpoint inside a DB transaction. A denormalised `journals.locked_until` date lets `JournalTransaction` model events reject creates/updates/deletes that touch the frozen range (`PeriodClosed`). All existing balance methods transparently start from the nearest checkpoint. Ledger-level checkpointing is a bulk convenience over member journals; there is no ledger checkpoint storage.

**Tech Stack:** PHP ^8.2, Laravel ^12 (Eloquent model events, DB transactions), moneyphp/money ^4, Pest ^3 on Testbench 10, Larastan 3, Pint.

**Spec:** `docs/superpowers/specs/2026-07-12-period-checkpoints-design.md`

## Global Constraints

- Namespace `Academe\LaravelJournal`; `declare(strict_types=1);` in every `src/` file; money as integer minor units, never floats.
- Model classes resolve through the container singleton `app(JournalModels::class)` (phase 2 pattern) — never raw `config('journal.models.*')` reads in new code.
- Checkpoint semantics: totals cover all transactions with `post_date` ≤ **end of** `checkpoint_date` (a date, not datetime), matching `balanceOn()`'s end-of-day behaviour; sums filter `currency_code` = journal currency, via SQL `sum()` (no collection loads).
- Freeze rule: postings/edits/deletes with an affected `post_date` ≤ end of `locked_until` throw `PeriodClosed`.
- Checkpoint removal is newest-first only, via `removeCheckpointsSince($date)` (inclusive ≥).
- Correctness property: every balance method returns identical `Money` with checkpoints present as without.
- NO git commits — the user commits manually. Skip every commit step; verify with test runs instead.
- Quality gates after each task: focused Pest run green, then full `vendor/bin/pest` green, `vendor/bin/phpstan analyse` 0 errors, `vendor/bin/pint --test` clean. Fix phpstan findings on your own new code (docblock generics/`class-string` narrowing per existing convention).
- **Concurrent-session warning:** phase 2 work may be happening in the same tree from another session. Before Task 1, run `vendor/bin/pest` and record the baseline test count; "all tests pass" always means baseline + your additions, never a hardcoded count.
- Windows: run Pest as `vendor\bin\pest` (PowerShell) or `vendor/bin/pest` (Git Bash).

---

### Task 1: Schema, config, exceptions, resolver

**Files:**
- Create: `database/migrations/2026_07_12_000000_create_journal_checkpoints_table.php`
- Create: `src/Exceptions/PeriodClosed.php`
- Create: `src/Exceptions/InvalidCheckpointDate.php`
- Modify: `config/journal.php` (add `models.checkpoint`)
- Modify: `src/JournalModels.php` (add `checkpoint()`)
- Test: `tests/Feature/MigrationsTest.php` (append), `tests/Unit/ExceptionsTest.php` (append)

**Interfaces:**
- Consumes: existing `JournalModels::resolve(string $key, string $base)` protected helper; `JournalException` base.
- Produces: table `journal_checkpoints` (id, timestamps, journal_id FK indexed, checkpoint_date date, debit_total bigint, credit_total bigint, currency_code char(3), unique(journal_id, checkpoint_date)); nullable `journals.locked_until` date column; `PeriodClosed` and `InvalidCheckpointDate` (both extend `JournalException`, zero-arg constructible); config key `journal.models.checkpoint` defaulting to `Academe\LaravelJournal\Models\JournalCheckpoint`; `JournalModels::checkpoint(): string` (class-string). Note: the `JournalCheckpoint` class itself is created in Task 2 — `::class` in config and the resolver's base-class argument reference it lexically, which is fine before the class exists as long as nothing calls `checkpoint()` yet; the resolver test in this task therefore only asserts the config default string.

- [ ] **Step 1: Record the baseline**

Run: `vendor\bin\pest`
Record the passing test count (baseline). Expected: all green (60+ tests; phase 2 may have added more).

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/MigrationsTest.php`:

```php
it('creates the journal_checkpoints table', function () {
    expect(Schema::hasTable('journal_checkpoints'))->toBeTrue();
    expect(Schema::hasColumns('journal_checkpoints', [
        'id', 'journal_id', 'checkpoint_date', 'debit_total', 'credit_total', 'currency_code',
    ]))->toBeTrue();
});

it('adds locked_until to journals', function () {
    expect(Schema::hasColumn('journals', 'locked_until'))->toBeTrue();
});
```

Append to `tests/Unit/ExceptionsTest.php` — extend the existing `it('extends JournalException with a default message', ...)` dataset with two new rows:

```php
    [PeriodClosed::class, 'closed'],
    [InvalidCheckpointDate::class, 'after the latest'],
```

and add the imports `use Academe\LaravelJournal\Exceptions\PeriodClosed;` and `use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;`.

Also append to `tests/Unit/ServiceProviderTest.php`:

```php
it('registers the checkpoint model in config', function () {
    expect(config('journal.models.checkpoint'))
        ->toBe('Academe\LaravelJournal\Models\JournalCheckpoint');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/MigrationsTest.php tests/Unit/ExceptionsTest.php tests/Unit/ServiceProviderTest.php`
Expected: FAIL — missing table, missing classes, missing config key.

- [ ] **Step 4: Create the migration**

`database/migrations/2026_07_12_000000_create_journal_checkpoints_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('journal_id')
                ->index()
                ->constrained('journals');

            // Totals cover all transactions with post_date <= end of this day.
            $table->date('checkpoint_date');

            // Cumulative minor units from the beginning of time through
            // checkpoint_date. Both sides are stored so the one-sided
            // balance methods accelerate too.
            $table->bigInteger('debit_total');
            $table->bigInteger('credit_total');

            // ISO 4217; copied from the journal at creation.
            $table->string('currency_code', 3);

            $table->unique(['journal_id', 'checkpoint_date']);
        });

        Schema::table('journals', function (Blueprint $table) {
            // The journal's latest checkpoint date. Postings dated at or
            // before the end of this day are rejected (PeriodClosed).
            $table->date('locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropColumn('locked_until');
        });

        Schema::dropIfExists('journal_checkpoints');
    }
};
```

- [ ] **Step 5: Create the exceptions**

`src/Exceptions/PeriodClosed.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class PeriodClosed extends JournalException
{
    public function __construct(string $message = 'The accounting period for this journal is closed.')
    {
        parent::__construct($message);
    }
}
```

`src/Exceptions/InvalidCheckpointDate.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class InvalidCheckpointDate extends JournalException
{
    public function __construct(string $message = 'Checkpoint date must be after the latest existing checkpoint.')
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 6: Add the config key and resolver method**

In `config/journal.php`, add to the imports `use Academe\LaravelJournal\Models\JournalCheckpoint;` and to the `models` array:

```php
        'checkpoint' => JournalCheckpoint::class,
```

In `src/JournalModels.php`, add the import `use Academe\LaravelJournal\Models\JournalCheckpoint;` and the method (after `transaction()`):

```php
    /**
     * @return class-string<JournalCheckpoint>
     */
    public function checkpoint(): string
    {
        return $this->resolve('checkpoint', JournalCheckpoint::class);
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/MigrationsTest.php tests/Unit/ExceptionsTest.php tests/Unit/ServiceProviderTest.php`
Expected: PASS.

- [ ] **Step 8: Full gates**

Run: `vendor\bin\pest` (baseline + 4 new green), `vendor\bin\phpstan analyse` (0 errors — note: `JournalModels::checkpoint()` references the not-yet-existing `JournalCheckpoint`; if phpstan flags `class.notFound`, add a narrowly scoped `// @phpstan-ignore class.notFound (created in the next task)` and REMOVE it in Task 2), `vendor\bin\pint --test`.

---

### Task 2: JournalCheckpoint model and Journal::checkpoint()

**Files:**
- Create: `src/Models/JournalCheckpoint.php`
- Modify: `src/Models/Journal.php` (add `locked_until` cast/property, `checkpoints()`, `latestCheckpoint()`, `checkpointOnOrBefore()`, `transactionsAfterCheckpoint()`, `checkpoint()`)
- Test: `tests/Feature/CheckpointTest.php` (new)

**Interfaces:**
- Consumes: Task 1's table, exceptions, `JournalModels::checkpoint()`; existing `makeUserJournal()` helper in `tests/Pest.php`; existing casts.
- Produces:
  - `JournalCheckpoint` model — `$guarded = ['id']`, casts (`checkpoint_date` date, `currency` CurrencyCast, `debit_total`/`credit_total` MoneyCast), `journal(): BelongsTo`.
  - `Journal::checkpoints(): HasMany`; `Journal::latestCheckpoint(): ?JournalCheckpoint`; `Journal::checkpoint(CarbonInterface|string $date): JournalCheckpoint` (throws `InvalidCheckpointDate` unless strictly after latest; incremental totals; sets `locked_until`; all in one `DB::transaction`).
  - Protected helpers Tasks 3 reuses: `checkpointOnOrBefore(CarbonInterface $date): ?JournalCheckpoint` and `transactionsAfterCheckpoint(?JournalCheckpoint $checkpoint, ?CarbonInterface $through = null): HasMany` (fresh builder: currency-filtered, `post_date` > checkpoint end-of-day if checkpoint given, ≤ `$through` end-of-day if given).
  - `Journal` gains `@property CarbonInterface|null $locked_until` and the `'locked_until' => 'date'` cast.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CheckpointTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Academe\LaravelJournal\Models\JournalCheckpoint;
use Money\Money;

it('creates a first checkpoint with cumulative totals and locks the journal', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(10000), 'old income', now()->subDays(10));
    $journal->debit(Money::USD(4000), 'old cost', now()->subDays(9));
    $journal->credit(Money::USD(700), 'recent', now());

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint)->toBeInstanceOf(JournalCheckpoint::class);
    expect($checkpoint->credit_total)->toEqual(Money::USD(10000));
    expect($checkpoint->debit_total)->toEqual(Money::USD(4000));
    expect($checkpoint->currency_code)->toBe('USD');
    expect($journal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
});

it('builds later checkpoints incrementally to the same result as a full sum', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(100), 'window 1', now()->subDays(30));
    $journal->debit(Money::USD(30), 'window 2', now()->subDays(15));
    $journal->credit(Money::USD(50), 'window 2', now()->subDays(12));
    $journal->credit(Money::USD(999), 'open tail', now());

    $journal->checkpoint(now()->subDays(20));
    $second = $journal->checkpoint(now()->subDays(10));

    // Second checkpoint = window 1 + window 2, exactly what a full
    // recompute through subDays(10) gives.
    expect($second->credit_total)->toEqual(Money::USD(150));
    expect($second->debit_total)->toEqual(Money::USD(30));

    expect($journal->checkpoints()->count())->toBe(2);
    expect($journal->latestCheckpoint()->checkpoint_date->toDateString())
        ->toBe(now()->subDays(10)->toDateString());
});

it('rejects a checkpoint dated on or before the latest', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(10));

    $journal->checkpoint(now()->subDays(10));
})->throws(InvalidCheckpointDate::class);

it('rejects a checkpoint dated before the latest', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(10));

    $journal->checkpoint(now()->subDays(20));
})->throws(InvalidCheckpointDate::class);

it('accepts a string date', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(500), null, now()->subDays(10));

    $checkpoint = $journal->checkpoint(now()->subDays(5)->toDateString());

    expect($checkpoint->credit_total)->toEqual(Money::USD(500));
});

it('includes transactions dated exactly on the checkpoint day', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), 'on the day', now()->subDays(5)->setTime(23, 30, 0));

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint->credit_total)->toEqual(Money::USD(100));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/CheckpointTest.php`
Expected: FAIL — `Class "Academe\LaravelJournal\Models\JournalCheckpoint" not found` / undefined method `checkpoint()`.

- [ ] **Step 3: Create the JournalCheckpoint model**

`src/Models/JournalCheckpoint.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
use Academe\LaravelJournal\JournalModels;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Money\Currency;
use Money\Money;

/**
 * A frozen balance fixed point: cumulative debit and credit totals for
 * one journal covering every transaction with a post date up to the end
 * of the checkpoint date. Balance queries start from the nearest
 * checkpoint instead of summing all history.
 *
 * @property int $id
 * @property int $journal_id
 * @property CarbonInterface $checkpoint_date
 * @property Money $debit_total
 * @property Money $credit_total
 * @property Currency $currency
 * @property string $currency_code ISO 4217
 * @property Journal $journal
 */
class JournalCheckpoint extends Model
{
    protected $table = 'journal_checkpoints';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checkpoint_date' => 'date',
            'currency' => CurrencyCast::class.':currency_code',
            'debit_total' => MoneyCast::class.':currency_code,debit_total',
            'credit_total' => MoneyCast::class.':currency_code,credit_total',
        ];
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(app(JournalModels::class)->journal());
    }
}
```

- [ ] **Step 4: Add checkpoint support to Journal**

In `src/Models/Journal.php`:

Add imports:

```php
use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Illuminate\Support\Facades\DB;
```

Add `@property CarbonInterface|null $locked_until` to the class docblock, and `'locked_until' => 'date',` to the `casts()` array.

Add these methods (after `transactions()`):

```php
    /**
     * @return HasMany<JournalCheckpoint, $this>
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(app(JournalModels::class)->checkpoint());
    }

    /**
     * The most recent checkpoint, or null if the journal has none.
     */
    public function latestCheckpoint(): ?JournalCheckpoint
    {
        return $this->checkpoints()
            ->orderByDesc('checkpoint_date')
            ->first();
    }

    /**
     * The most recent checkpoint covering the end of the given day,
     * or null if none exists that early.
     */
    protected function checkpointOnOrBefore(CarbonInterface $date): ?JournalCheckpoint
    {
        return $this->checkpoints()
            ->where('checkpoint_date', '<=', $date->toDateString())
            ->orderByDesc('checkpoint_date')
            ->first();
    }

    /**
     * Fresh transaction query for the window after a checkpoint
     * (exclusive) up to the end of a given day (inclusive), in the
     * journal currency. Null bounds are open.
     *
     * @return HasMany<JournalTransaction, $this>
     */
    protected function transactionsAfterCheckpoint(
        ?JournalCheckpoint $checkpoint,
        ?CarbonInterface $through = null,
    ): HasMany {
        $query = $this->transactions()
            ->where('currency_code', $this->currency_code);

        if ($checkpoint !== null) {
            $query->where('post_date', '>', $checkpoint->checkpoint_date->copy()->endOfDay());
        }

        if ($through !== null) {
            $query->where('post_date', '<=', $through->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * Create a checkpoint: freeze the journal through the end of the
     * given day and store cumulative totals for balance queries to
     * start from.
     *
     * Totals are computed incrementally from the previous checkpoint.
     * The computation, insert, and locked_until update run in one
     * database transaction so a concurrent posting cannot fall between
     * the sum and the freeze.
     *
     * @throws InvalidCheckpointDate when $date is not strictly after
     *         the latest existing checkpoint
     */
    public function checkpoint(CarbonInterface|string $date): JournalCheckpoint
    {
        $date = $date instanceof CarbonInterface
            ? Carbon::instance($date)->startOfDay()
            : Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($date): JournalCheckpoint {
            $previous = $this->latestCheckpoint();

            if ($previous !== null
                && $date->toDateString() <= $previous->checkpoint_date->toDateString()
            ) {
                throw new InvalidCheckpointDate(sprintf(
                    'Checkpoint date %s must be after the latest checkpoint %s for journal %d.',
                    $date->toDateString(),
                    $previous->checkpoint_date->toDateString(),
                    $this->id,
                ));
            }

            $debitTail = (int) $this->transactionsAfterCheckpoint($previous, $date)->sum('debit');
            $creditTail = (int) $this->transactionsAfterCheckpoint($previous, $date)->sum('credit');

            $zero = new Money(0, $this->currency);

            $checkpointClass = app(JournalModels::class)->checkpoint();

            /** @var JournalCheckpoint $checkpoint */
            $checkpoint = new $checkpointClass;

            $checkpoint->checkpoint_date = $date;
            $checkpoint->currency = $this->currency;
            $checkpoint->debit_total = ($previous?->debit_total ?? $zero)
                ->add(new Money($debitTail, $this->currency));
            $checkpoint->credit_total = ($previous?->credit_total ?? $zero)
                ->add(new Money($creditTail, $this->currency));

            $this->checkpoints()->save($checkpoint);

            $this->locked_until = $date;
            $this->save();

            return $checkpoint;
        });
    }
```

Remove the Task 1 `@phpstan-ignore` in `JournalModels.php` if one was added (the class now exists).

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/CheckpointTest.php`
Expected: PASS — 6 tests.

- [ ] **Step 6: Full gates**

Run: `vendor\bin\pest`, `vendor\bin\phpstan analyse`, `vendor\bin\pint --test`. All green.

---

### Task 3: Balance-method integration

**Files:**
- Modify: `src/Models/Journal.php:89-145` (`debitBalanceOn()`, `creditBalanceOn()`, `totalBalance()`)
- Test: `tests/Feature/CheckpointBalanceTest.php` (new)

**Interfaces:**
- Consumes: `checkpointOnOrBefore()`, `latestCheckpoint()`, `transactionsAfterCheckpoint()` from Task 2.
- Produces: same public signatures as today — `debitBalanceOn(CarbonInterface $date): Money`, `creditBalanceOn(CarbonInterface $date): Money`, `totalBalance(): Money` — now starting from checkpoint totals. `balanceOn()`, `currentBalance()`, `resetCurrentBalance()` are untouched (they derive from these three).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CheckpointBalanceTest.php`. The core is an equivalence property: two journals with identical transactions, one checkpointed twice, must agree on every balance method at every probe date.

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\Journal;
use Money\Money;

/**
 * Post the same transaction set to both journals: three closed-period
 * windows, an open tail, and a future-dated entry.
 */
function postIdenticalHistory(Journal $a, Journal $b): void
{
    foreach ([$a, $b] as $journal) {
        $journal->credit(Money::USD(10000), 'w1 income', now()->subDays(30));
        $journal->debit(Money::USD(2500), 'w1 cost', now()->subDays(28));
        $journal->credit(Money::USD(300), 'w2 income', now()->subDays(15));
        $journal->debit(Money::USD(450), 'w2 cost', now()->subDays(14));
        $journal->credit(Money::USD(77), 'open tail', now()->subDays(2));
        $journal->debit(Money::USD(9), 'future', now()->addDays(7));
    }
}

it('returns identical balances with and without checkpoints', function () {
    $plain = makeUserJournal();
    $checkpointed = Journal::create([
        'currency_code' => 'USD',
        'owner_type' => $plain->owner_type,
        'owner_id' => $plain->owner_id + 1000, // distinct owner id; no owner row needed
    ]);

    postIdenticalHistory($plain, $checkpointed);

    $checkpointed->checkpoint(now()->subDays(20));
    $checkpointed->checkpoint(now()->subDays(10));

    $probes = [
        now()->subDays(25), // between the two windows, before first checkpoint
        now()->subDays(20), // exactly on a checkpoint date
        now()->subDays(12), // between checkpoints
        now()->subDays(1),  // open tail
        now(),              // today
        now()->addDays(30), // beyond the future entry
    ];

    foreach ($probes as $probe) {
        expect($checkpointed->balanceOn($probe))
            ->toEqual($plain->balanceOn($probe));
        expect($checkpointed->debitBalanceOn($probe))
            ->toEqual($plain->debitBalanceOn($probe));
        expect($checkpointed->creditBalanceOn($probe))
            ->toEqual($plain->creditBalanceOn($probe));
    }

    expect($checkpointed->currentBalance())->toEqual($plain->currentBalance());
    expect($checkpointed->totalBalance())->toEqual($plain->totalBalance());
});

it('answers dates before the first checkpoint by summing from zero', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(30));
    $journal->credit(Money::USD(50), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(20));

    expect($journal->balanceOn(now()->subDays(25)))->toEqual(Money::USD(100));
});

it('keeps the cached balance correct when posting after a checkpoint', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $journal->credit(Money::USD(40), null, now());

    expect($journal->fresh()->balance)->toEqual(Money::USD(140));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/CheckpointBalanceTest.php`
Expected: the equivalence test PASSES already (both paths sum everything today) — but it fails for the right reason only after implementation changes the query shape. To get a genuine RED, temporarily note: the first two tests pass pre-implementation (old code sums from zero and is correct); the third also passes. This task's tests are a REGRESSION NET, not a RED/GREEN driver — the implementation must keep them green while changing the mechanics. Run them, confirm green, then implement and confirm they STAY green. (This is the property-test pattern: the contract is "results identical", so pre- and post-implementation both satisfy it.)

- [ ] **Step 3: Reimplement the three balance methods**

Replace `debitBalanceOn()`, `creditBalanceOn()`, and `totalBalance()` in `src/Models/Journal.php`:

```php
    /**
     * The debit-only balance at the end of the given day.
     *
     * Starts from the nearest checkpoint at or before the date, so only
     * transactions after that checkpoint are scanned.
     */
    public function debitBalanceOn(CarbonInterface $date): Money
    {
        $checkpoint = $this->checkpointOnOrBefore($date);

        $tailMinorUnits = (int) $this->transactionsAfterCheckpoint($checkpoint, $date)
            ->sum('debit');

        return ($checkpoint?->debit_total ?? new Money(0, $this->currency))
            ->add(new Money($tailMinorUnits, $this->currency));
    }

    /**
     * The credit-only balance at the end of the given day.
     *
     * Starts from the nearest checkpoint at or before the date, so only
     * transactions after that checkpoint are scanned.
     */
    public function creditBalanceOn(CarbonInterface $date): Money
    {
        $checkpoint = $this->checkpointOnOrBefore($date);

        $tailMinorUnits = (int) $this->transactionsAfterCheckpoint($checkpoint, $date)
            ->sum('credit');

        return ($checkpoint?->credit_total ?? new Money(0, $this->currency))
            ->add(new Money($tailMinorUnits, $this->currency));
    }

    /**
     * The balance across all transactions, including future-dated ones.
     *
     * Starts from the latest checkpoint, so only transactions after it
     * are scanned.
     */
    public function totalBalance(): Money
    {
        $checkpoint = $this->latestCheckpoint();

        $credit = (int) $this->transactionsAfterCheckpoint($checkpoint)->sum('credit');
        $debit = (int) $this->transactionsAfterCheckpoint($checkpoint)->sum('debit');

        $start = $checkpoint !== null
            ? $checkpoint->credit_total->subtract($checkpoint->debit_total)
            : new Money(0, $this->currency);

        return $start->add(new Money($credit - $debit, $this->currency));
    }
```

(`balanceOn()`, `currentBalance()`, `resetCurrentBalance()` are left exactly as they are.)

- [ ] **Step 4: Run tests to verify they still pass**

Run: `vendor\bin\pest tests/Feature/CheckpointBalanceTest.php tests/Feature/CheckpointTest.php tests/Feature/PostingTest.php tests/Feature/LedgerTest.php`
Expected: PASS — the equivalence net plus every pre-existing balance test.

- [ ] **Step 5: Full gates**

Run: `vendor\bin\pest`, `vendor\bin\phpstan analyse`, `vendor\bin\pint --test`. All green.

---

### Task 4: Freeze guards

**Files:**
- Modify: `src/Models/JournalTransaction.php` (guards in `booted()`, new `guardFrozenPeriod()` method)
- Test: `tests/Feature/FreezeGuardTest.php` (new)

**Interfaces:**
- Consumes: `journals.locked_until` (date cast) from Tasks 1–2; `PeriodClosed` from Task 1; `companyBooks()` and `makeUserJournal()` helpers.
- Produces: `creating`/`updating`/`deleting` events on `JournalTransaction` that throw `PeriodClosed` when the affected `post_date` (original AND new, for updates) is ≤ end of the journal's `locked_until`. `TransactionGroup` needs no changes — mid-commit `PeriodClosed` rolls back atomically and is wrapped in `TransactionCouldNotBeProcessed`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FreezeGuardTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\TransactionGroup;
use Money\Money;

it('rejects posting into a closed period', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(5));

    $journal->credit(Money::USD(100), 'backdated', now()->subDays(10));
})->throws(PeriodClosed::class);

it('rejects posting dated exactly on the lock date', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(5));

    $journal->credit(Money::USD(100), 'on the closed day', now()->subDays(5)->setTime(12, 0, 0));
})->throws(PeriodClosed::class);

it('allows posting after the lock date', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $transaction = $journal->credit(Money::USD(40), 'open period', now()->subDays(2));

    expect($transaction->exists)->toBeTrue();
    expect($journal->fresh()->balance)->toEqual(Money::USD(140));
});

it('rejects editing a frozen transaction', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $transaction->memo = 'rewriting history';
    $transaction->save();
})->throws(PeriodClosed::class);

it('rejects moving a transaction into a frozen period', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now());
    $journal->checkpoint(now()->subDays(5));

    $transaction->post_date = now()->subDays(10);
    $transaction->save();
})->throws(PeriodClosed::class);

it('rejects deleting a frozen transaction', function () {
    $journal = makeUserJournal();
    $transaction = $journal->credit(Money::USD(100), null, now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    $transaction->delete();
})->throws(PeriodClosed::class);

it('rolls back a transaction group touching a closed period', function () {
    $books = companyBooks();
    $books->cashJournal->checkpoint(now()->subDays(5));

    $caught = null;

    try {
        TransactionGroup::make()
            ->addTransaction($books->incomeJournal, 'credit', Money::USD(100), null, null, now()->subDays(10))
            ->addTransaction($books->cashJournal, 'debit', Money::USD(100), null, null, now()->subDays(10))
            ->commit();
    } catch (TransactionCouldNotBeProcessed $caught) {
    }

    expect($caught)->toBeInstanceOf(TransactionCouldNotBeProcessed::class);
    expect($caught->getPrevious())->toBeInstanceOf(PeriodClosed::class);
    expect(JournalTransaction::count())->toBe(0);
    expect($books->incomeJournal->fresh()->balance)->toEqual(Money::USD(0));
});
```

(Note the group test lists the unlocked income journal FIRST so its entry succeeds before the locked cash journal throws — proving the income entry is rolled back.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/FreezeGuardTest.php`
Expected: FAIL — the frozen postings succeed instead of throwing.

- [ ] **Step 3: Implement the guards**

In `src/Models/JournalTransaction.php`, add imports:

```php
use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Carbon\CarbonInterface;
```

Extend `booted()` — the guards go BEFORE the existing saved/deleted hooks:

```php
    protected static function booted(): void
    {
        // Reject writes that touch a closed (checkpointed) period.
        static::creating(function (self $transaction) {
            $transaction->guardFrozenPeriod($transaction->post_date);
        });

        static::updating(function (self $transaction) {
            $original = $transaction->getRawOriginal('post_date');

            if ($original !== null) {
                $transaction->guardFrozenPeriod(Carbon::parse($original));
            }

            $transaction->guardFrozenPeriod($transaction->post_date);
        });

        static::deleting(function (self $transaction) {
            $transaction->guardFrozenPeriod($transaction->post_date);
        });

        // Keep the cached journal balance in sync.
        static::saved(function (self $transaction) {
            $transaction->journal->resetCurrentBalance();
        });

        static::deleted(function (self $transaction) {
            $transaction->journal->resetCurrentBalance();
        });
    }
```

Add the guard method (after `reference()`):

```php
    /**
     * Reject any write whose post date falls in the journal's closed
     * period. The lock is the journal's latest checkpoint date; correct
     * a closed period with an adjusting entry in the open period, or by
     * removing checkpoints first.
     *
     * @throws PeriodClosed
     */
    protected function guardFrozenPeriod(?CarbonInterface $postDate): void
    {
        if ($postDate === null) {
            return;
        }

        $lockedUntil = $this->journal?->locked_until;

        if ($lockedUntil !== null
            && $postDate->lessThanOrEqualTo($lockedUntil->copy()->endOfDay())
        ) {
            throw new PeriodClosed(sprintf(
                'Journal %d is closed through %s; cannot post, change, or delete a transaction dated %s.',
                $this->journal->id,
                $lockedUntil->toDateString(),
                $postDate->toDateString(),
            ));
        }
    }
```

Cost note (document in the report, no action needed): the `creating` guard lazy-loads `$this->journal` once; the existing `saved` hook already loads it, so the relation is simply loaded earlier and cached — no additional query per posting.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/FreezeGuardTest.php`
Expected: PASS — 7 tests.

- [ ] **Step 5: Full gates**

Run: `vendor\bin\pest`, `vendor\bin\phpstan analyse`, `vendor\bin\pint --test`. All green.

---

### Task 5: Checkpoint removal and the reopen cycle

**Files:**
- Modify: `src/Models/Journal.php` (add `removeCheckpointsSince()`)
- Test: `tests/Feature/CheckpointTest.php` (append)

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: `Journal::removeCheckpointsSince(CarbonInterface|string $date): int` — deletes checkpoints dated ≥ `$date` (inclusive), resets `locked_until` to the new latest checkpoint date or null, one DB transaction, returns count removed.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CheckpointTest.php`:

```php
it('reopens, corrects, and re-checkpoints with new sums', function () {
    $journal = makeUserJournal();
    $wrong = $journal->credit(Money::USD(10000), 'wrong amount', now()->subDays(10));
    $journal->checkpoint(now()->subDays(5));

    // Frozen: the bad entry cannot be deleted...
    expect(fn () => $wrong->delete())
        ->toThrow(Academe\LaravelJournal\Exceptions\PeriodClosed::class);

    // ...until the checkpoint is removed.
    $removed = $journal->removeCheckpointsSince(now()->subDays(5));

    expect($removed)->toBe(1);
    expect($journal->fresh()->locked_until)->toBeNull();

    $wrong->delete();
    $journal->credit(Money::USD(9000), 'corrected amount', now()->subDays(10));

    $again = $journal->checkpoint(now()->subDays(5));

    expect($again->credit_total)->toEqual(Money::USD(9000));
    expect($journal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
});

it('resets locked_until to the previous checkpoint after a partial removal', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(20));
    $journal->checkpoint(now()->subDays(10));

    $removed = $journal->removeCheckpointsSince(now()->subDays(10));

    expect($removed)->toBe(1);
    expect($journal->checkpoints()->count())->toBe(1);
    expect($journal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(20)->toDateString());
});

it('removal is inclusive of the given date and returns zero when nothing matches', function () {
    $journal = makeUserJournal();
    $journal->checkpoint(now()->subDays(10));

    expect($journal->removeCheckpointsSince(now()->subDays(5)))->toBe(0);
    expect($journal->fresh()->locked_until)->not->toBeNull();

    expect($journal->removeCheckpointsSince(now()->subDays(10)))->toBe(1);
    expect($journal->fresh()->locked_until)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/CheckpointTest.php`
Expected: FAIL — undefined method `removeCheckpointsSince()`.

- [ ] **Step 3: Implement removal**

Add to `src/Models/Journal.php` (after `checkpoint()`):

```php
    /**
     * Remove all checkpoints dated on or after the given date, reopening
     * those periods for correction. Newest-first by construction: there
     * is no way to remove a checkpoint from the middle of the series.
     *
     * Reopen workflow: removeCheckpointsSince() -> post corrections ->
     * checkpoint() again (fresh sums are computed on re-checkpoint).
     *
     * @return int the number of checkpoints removed
     */
    public function removeCheckpointsSince(CarbonInterface|string $date): int
    {
        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($dateString): int {
            $removed = $this->checkpoints()
                ->where('checkpoint_date', '>=', $dateString)
                ->delete();

            $this->locked_until = $this->latestCheckpoint()?->checkpoint_date;
            $this->save();

            return $removed;
        });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/CheckpointTest.php tests/Feature/FreezeGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Full gates**

Run: `vendor\bin\pest`, `vendor\bin\phpstan analyse`, `vendor\bin\pint --test`. All green.

---

### Task 6: Ledger bulk operations

**Files:**
- Modify: `src/Models/Ledger.php` (add `checkpoint()`, `removeCheckpointsSince()`)
- Test: `tests/Feature/LedgerCheckpointTest.php` (new)

**Interfaces:**
- Consumes: `Journal::checkpoint()` / `Journal::removeCheckpointsSince()` (Tasks 2, 5); `companyBooks()` helper (assetsLedger has two member journals: arJournal and cashJournal).
- Produces: `Ledger::checkpoint(CarbonInterface|string $date): int` (journals checkpointed; all-or-nothing) and `Ledger::removeCheckpointsSince(CarbonInterface|string $date): int` (checkpoints removed across members; all-or-nothing). No ledger checkpoint storage exists or is created.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/LedgerCheckpointTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Money\Money;

it('checkpoints every journal in the ledger', function () {
    $books = companyBooks();
    $books->cashJournal->debit(Money::USD(5000), null, now()->subDays(10));
    $books->arJournal->debit(Money::USD(2500), null, now()->subDays(10));

    $count = $books->assetsLedger->checkpoint(now()->subDays(5));

    expect($count)->toBe(2);
    expect($books->cashJournal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
    expect($books->arJournal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
    expect($books->cashJournal->latestCheckpoint()->debit_total)->toEqual(Money::USD(5000));
});

it('rolls the whole ledger checkpoint back when one journal fails', function () {
    $books = companyBooks();

    // arJournal already checkpointed more recently than the bulk date,
    // so its member checkpoint will throw InvalidCheckpointDate.
    $books->arJournal->checkpoint(now()->subDays(2));

    expect(fn () => $books->assetsLedger->checkpoint(now()->subDays(5)))
        ->toThrow(InvalidCheckpointDate::class);

    // The other member's checkpoint must have rolled back.
    expect($books->cashJournal->fresh()->locked_until)->toBeNull();
    expect($books->cashJournal->checkpoints()->count())->toBe(0);
});

it('removes checkpoints across the ledger', function () {
    $books = companyBooks();
    $books->assetsLedger->checkpoint(now()->subDays(10));
    $books->assetsLedger->checkpoint(now()->subDays(5));

    $removed = $books->assetsLedger->removeCheckpointsSince(now()->subDays(5));

    expect($removed)->toBe(2); // one per member journal
    expect($books->cashJournal->fresh()->locked_until->toDateString())
        ->toBe(now()->subDays(10)->toDateString());
});

it('a journal without a ledger checkpoints independently', function () {
    $journal = makeUserJournal();
    $journal->credit(Money::USD(100), null, now()->subDays(10));

    $checkpoint = $journal->checkpoint(now()->subDays(5));

    expect($checkpoint->credit_total)->toEqual(Money::USD(100));
    expect($journal->ledger_id)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/LedgerCheckpointTest.php`
Expected: FAIL — undefined method `Ledger::checkpoint()`.

- [ ] **Step 3: Implement the bulk operations**

In `src/Models/Ledger.php`, add imports:

```php
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
```

Add the methods (after `currentBalance()`):

```php
    /**
     * Checkpoint every journal in this ledger at the given date, in one
     * database transaction: if any member journal fails (for example it
     * already has a later checkpoint), the whole operation rolls back.
     *
     * This is a bulk convenience over Journal::checkpoint(); ledgers
     * hold no checkpoint data of their own.
     *
     * @return int the number of journals checkpointed
     */
    public function checkpoint(CarbonInterface|string $date): int
    {
        return DB::transaction(function () use ($date): int {
            $count = 0;

            foreach ($this->journals()->get() as $journal) {
                $journal->checkpoint($date);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Remove checkpoints dated on or after the given date from every
     * journal in this ledger, in one database transaction.
     *
     * @return int the total number of checkpoints removed
     */
    public function removeCheckpointsSince(CarbonInterface|string $date): int
    {
        return DB::transaction(function () use ($date): int {
            $removed = 0;

            foreach ($this->journals()->get() as $journal) {
                $removed += $journal->removeCheckpointsSince($date);
            }

            return $removed;
        });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/LedgerCheckpointTest.php`
Expected: PASS — 4 tests.

- [ ] **Step 5: Full gates**

Run: `vendor\bin\pest`, `vendor\bin\phpstan analyse`, `vendor\bin\pint --test`. All green.

---

### Task 7: Documentation and final verification

**Files:**
- Modify: `README.md`, `UPGRADE.md`, `CHANGELOG.md`

**Interfaces:**
- Consumes: the final phase-3 API. Before writing, re-read `src/Models/Journal.php`, `src/Models/Ledger.php`, `src/Models/JournalCheckpoint.php`, `src/Models/JournalTransaction.php` — every documented name and signature must match the code.

- [ ] **Step 1: README — add a "Checkpoints: fast balances and closed periods" section**

Place it after the Ledgers section. Required content (accurate prose, real signatures):

1. Why: balance queries sum full history; a checkpoint stores cumulative totals through a date so queries scan only what follows. All balance methods use checkpoints automatically — no API changes.
2. Creating: `$journal->checkpoint('2026-03-31')` (date or Carbon; totals through **end of** that day; must be later than the previous checkpoint; returns a `JournalCheckpoint`).
3. Closing semantics: the journal is locked through the checkpoint date — creating, editing, or deleting any transaction dated in the closed range throws `PeriodClosed`; correct closed periods with adjusting entries in the open period. Groups touching a locked journal roll back atomically.
4. Reopening: `$journal->removeCheckpointsSince('2026-01-01')` (inclusive, newest-first by construction) → correct → re-checkpoint; sums are recomputed fresh.
5. Ledger bulk: `$ledger->checkpoint($date)` / `$ledger->removeCheckpointsSince($date)` — all-or-nothing over member journals; ledgers store no checkpoint data; ledger-less journals checkpoint identically.
6. Warnings: a future-dated checkpoint freezes all posting until removed (allowed; the app's responsibility); raw `DB::` writes bypass the guards (same limitation as the cached balance); checkpoints assume the dates you close have complete data — with late imports (e.g. royalties up to 12 months behind), checkpoint only once the range is final.

- [ ] **Step 2: UPGRADE — additive note**

Add a short "1.0 → 1.1" section: one new migration (`journal_checkpoints` table + nullable `journals.locked_until`); republish migrations (`php artisan vendor:publish --tag=journal-migrations`) or copy the new file; zero behaviour change until the first `checkpoint()` call.

- [ ] **Step 3: CHANGELOG — 1.1.0 entry**

```markdown
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
```

- [ ] **Step 4: Verify docs against code**

Every class, method, signature, and behaviour claim in the new sections checked against `src/`. Fix drift; the code wins.

- [ ] **Step 5: Final gates**

Run: `vendor\bin\pest`, `vendor\bin\phpstan analyse`, `vendor\bin\pint --test`, `composer validate --strict`. All green.

---

## Post-plan notes (not tasks)

- The user commits manually — nothing in this plan touches git.
- Coordinate with the phase-2 session: if it edits the same models concurrently, re-run the full suite before starting each task and reconcile conflicts in favour of whatever is on disk.
- Deferred deliberately (spec "out of scope"): ledger rollup rows, DB triggers, transaction archiving.

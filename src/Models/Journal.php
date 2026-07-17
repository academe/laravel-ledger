<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
use Academe\LaravelJournal\Contracts\NamesJournal;
use Academe\LaravelJournal\Exceptions\CheckpointNotRemovable;
use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Exceptions\InvalidCheckpointDate;
use Academe\LaravelJournal\JournalModels;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Money\Currency;
use Money\Money;

/**
 * A journal records the transactions of a single owner model instance.
 *
 * @property Money|null $balance
 * @property string $currency_code ISO 4217
 * @property Currency|null $currency
 * @property CarbonInterface|null $locked_until
 * @property CarbonInterface $updated_at
 * @property CarbonInterface $created_at
 * @property Model|null $owner
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property Ledger|null $ledger
 * @property int|null $ledger_id
 */
class Journal extends Model
{
    protected $table = 'journals';

    protected $guarded = ['id'];

    protected $attributes = [
        'balance' => 0,
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCast::class.':currency_code',
            'balance' => MoneyCast::class.':currency_code,balance',
            'locked_until' => 'date',
        ];
    }

    /**
     * The model instance this journal belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    /**
     * A human-readable name for this journal, resolved through the owner:
     *
     *  1. Owner implements NamesJournal -> its journalDisplayName().
     *  2. Owner loads but has no interface -> "{type} #{owner_id}",
     *     where {type} is the morph alias as stored, or the class
     *     basename when owner_type is a FQCN (no morph map assumed).
     *  3. Owner missing or unloadable -> "journal #{id}".
     *
     * Lazy-loads the owner; intended internally for failure paths and display,
     * not hot loops.
     */
    public function displayName(): string
    {
        $owner = $this->resolvedOwner();

        if ($owner instanceof NamesJournal) {
            return $owner->journalDisplayName();
        }

        if ($owner !== null) {
            return class_basename((string) $this->owner_type).' #'.$this->owner_id;
        }

        return 'journal #'.$this->id;
    }

    /**
     * An optional longer description for this journal, resolved through
     * the owner. Null when the owner does not implement NamesJournal,
     * has nothing more to say, or is missing.
     */
    public function description(): ?string
    {
        $owner = $this->resolvedOwner();

        return $owner instanceof NamesJournal
            ? $owner->journalDescription()
            : null;
    }

    /**
     * The owner model, or null when the row is missing or owner_type no
     * longer resolves to a class (e.g. an unregistered morph alias).
     */
    protected function resolvedOwner(): ?Model
    {
        $ownerClass = $this->owner_type === null
            ? null
            : (Relation::getMorphedModel($this->owner_type) ?? $this->owner_type);

        return $ownerClass !== null && class_exists($ownerClass)
            ? $this->owner
            : null;
    }

    /**
     * @return BelongsTo<Ledger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(app(JournalModels::class)->ledger());
    }

    /**
     * @return HasMany<JournalTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(app(JournalModels::class)->transaction());
    }

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
     *                               the latest existing checkpoint
     */
    public function checkpoint(CarbonInterface|string $date): JournalCheckpoint
    {
        $date = $date instanceof CarbonInterface
            ? Carbon::instance($date)->startOfDay()
            : Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($date): JournalCheckpoint {
            // Lock this journal's row first, before any read that feeds
            // the checkpoint decision. A concurrent post() or the frozen-
            // period guard's own locked read will block on this row until
            // we commit, closing the race between summing and freezing.
            $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

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

            // One aggregate query so the debit and credit tails cannot be
            // torn apart by a commit landing between two separate sums.
            $totals = $this->transactionsAfterCheckpoint($previous, $date)
                ->selectRaw('COALESCE(SUM(debit), 0) AS debit_tail, COALESCE(SUM(credit), 0) AS credit_tail')
                ->toBase()
                ->first();

            $debitTail = (int) $totals->debit_tail;
            $creditTail = (int) $totals->credit_tail;

            $zero = new Money(0, $this->currency);

            $checkpointClass = app(JournalModels::class)->checkpoint();

            /** @var JournalCheckpoint $checkpoint */
            $checkpoint = new $checkpointClass;

            $checkpoint->checkpoint_date = $date;
            $checkpoint->currency = $this->currency;
            $checkpoint->debit_total = ($previous ? $previous->debit_total : $zero)
                ->add(new Money($debitTail, $this->currency));
            $checkpoint->credit_total = ($previous ? $previous->credit_total : $zero)
                ->add(new Money($creditTail, $this->currency));

            $this->checkpoints()->save($checkpoint);

            $this->locked_until = $date;
            $this->save();

            return $checkpoint;
        });
    }

    /**
     * Remove all checkpoints dated on or after the given date, reopening
     * those periods for correction. Newest-first by construction: there
     * is no way to remove a checkpoint from the middle of the series.
     *
     * Reopen workflow: removeCheckpointsSince() -> post corrections ->
     * checkpoint() again (fresh sums are computed on re-checkpoint).
     *
     * A checkpoint with non-zero totals that precedes every transaction
     * in the journal is a brought-forward starting point (an opening
     * balance seeded without underlying transaction rows): its totals
     * cannot be recomputed, so it is never removed.
     *
     * @return int the number of checkpoints removed
     *
     * @throws CheckpointNotRemovable when the range includes the
     *                                journal's opening-balance checkpoint
     */
    public function removeCheckpointsSince(CarbonInterface|string $date): int
    {
        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($dateString): int {
            $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            $earliest = $this->checkpoints()
                ->orderBy('checkpoint_date')
                ->first();

            if ($earliest !== null
                && $earliest->checkpoint_date->toDateString() >= $dateString
                && ! ($earliest->debit_total->isZero() && $earliest->credit_total->isZero())
                && ! $this->transactions()
                    ->where('currency_code', $this->currency_code)
                    ->where('post_date', '<=', $earliest->checkpoint_date->copy()->endOfDay())
                    ->exists()
            ) {
                throw new CheckpointNotRemovable(sprintf(
                    "The checkpoint at %s precedes all of journal %d's transactions; "
                        ."it is the journal's starting point and its totals cannot be "
                        .'recomputed. Remove checkpoints after it only.',
                    $earliest->checkpoint_date->toDateString(),
                    $this->id,
                ));
            }

            $removed = $this->checkpoints()
                ->where('checkpoint_date', '>=', $dateString)
                ->delete();

            $this->locked_until = $this->latestCheckpoint()?->checkpoint_date;
            $this->save();

            return $removed;
        });
    }

    /**
     * Recompute and save the cached balance column.
     *
     * Uses the total balance, which includes future-dated transactions.
     */
    public function resetCurrentBalance(): Money
    {
        $this->balance = $this->totalBalance();
        $this->save();

        return $this->balance;
    }

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

        return ($checkpoint ? $checkpoint->debit_total : new Money(0, $this->currency))
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

        return ($checkpoint ? $checkpoint->credit_total : new Money(0, $this->currency))
            ->add(new Money($tailMinorUnits, $this->currency));
    }

    /**
     * The balance (credit - debit) at the end of the given day.
     */
    public function balanceOn(CarbonInterface $date): Money
    {
        return $this->creditBalanceOn($date)->subtract($this->debitBalanceOn($date));
    }

    /**
     * The balance today, excluding future-dated transactions.
     */
    public function currentBalance(): Money
    {
        return $this->balanceOn(Carbon::now());
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

    /**
     * Post a credit entry. An integer value means minor units in the
     * journal currency; a Money value must match the journal currency.
     *
     * $transactionGroup is stored in a `uuid` column: it must be a UUID
     * string (or null) on strict databases such as PostgreSQL.
     */
    public function credit(
        Money|int $value,
        ?string $memo = null,
        ?CarbonInterface $postDate = null,
        ?string $transactionGroup = null,
    ): JournalTransaction {
        return $this->post(
            credit: $this->normalizeAmount($value),
            debit: null,
            memo: $memo,
            postDate: $postDate,
            transactionGroup: $transactionGroup,
        );
    }

    /**
     * Post a debit entry. An integer value means minor units in the
     * journal currency; a Money value must match the journal currency.
     *
     * $transactionGroup is stored in a `uuid` column: it must be a UUID
     * string (or null) on strict databases such as PostgreSQL.
     */
    public function debit(
        Money|int $value,
        ?string $memo = null,
        ?CarbonInterface $postDate = null,
        ?string $transactionGroup = null,
    ): JournalTransaction {
        return $this->post(
            credit: null,
            debit: $this->normalizeAmount($value),
            memo: $memo,
            postDate: $postDate,
            transactionGroup: $transactionGroup,
        );
    }

    /**
     * Convert an input amount to absolute Money in the journal currency.
     *
     * @throws CurrencyMismatch
     */
    protected function normalizeAmount(Money|int $value): Money
    {
        if ($value instanceof Money) {
            if (! $value->getCurrency()->equals($this->currency)) {
                throw new CurrencyMismatch($value->getCurrency(), $this->currency);
            }

            return $value->absolute();
        }

        return new Money(abs($value), $this->currency);
    }

    /**
     * Create and save the journal entry.
     */
    protected function post(
        ?Money $credit,
        ?Money $debit,
        ?string $memo,
        ?CarbonInterface $postDate,
        ?string $transactionGroup,
    ): JournalTransaction {
        $transactionClass = app(JournalModels::class)->transaction();

        $transaction = new $transactionClass;

        $transaction->credit = $credit;
        $transaction->debit = $debit;
        $transaction->currency = $this->currency;
        $transaction->memo = $memo;
        $transaction->post_date = $postDate instanceof Carbon ? $postDate : ($postDate ? Carbon::instance($postDate) : Carbon::now());
        $transaction->transaction_group = $transactionGroup;

        $this->transactions()->save($transaction);

        return $transaction;
    }

    public function assignToLedger(Ledger $ledger): self
    {
        $ledger->journals()->save($this);

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
use Academe\LaravelJournal\Casts\TagsCast;
use Academe\LaravelJournal\Exceptions\PeriodClosed;
use Academe\LaravelJournal\JournalModels;
use Academe\LaravelJournal\PendingBalanceUpdates;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Money\Currency;
use Money\Money;

/**
 * A single journal entry: either a credit or a debit, in minor units.
 *
 * @property string $id
 * @property int $journal_id
 * @property string|null $transaction_group
 * @property Money|null $credit
 * @property Money|null $debit
 * @property Money $amount credit as positive, debit as negative
 * @property Currency $currency
 * @property string $currency_code ISO 4217
 * @property string|null $memo
 * @property array<string, bool|int|float|string> $tags
 * @property Journal $journal
 * @property Carbon $post_date
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 */
class JournalTransaction extends Model
{
    use HasUuids;

    protected $table = 'journal_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'post_date' => 'datetime',
            'tags' => TagsCast::class,
            'currency' => CurrencyCast::class.':currency_code',
            'credit' => MoneyCast::class.':currency_code,credit',
            'debit' => MoneyCast::class.':currency_code,debit',
        ];
    }

    protected static function booted(): void
    {
        // Reject writes that touch a closed (checkpointed) period.
        static::creating(function (self $transaction) {
            $transaction->guardFrozenPeriod($transaction->post_date);
        });

        static::updating(function (self $transaction) {
            $original = $transaction->getRawOriginal('post_date');

            $transaction->guardFrozenPeriod(
                $original !== null ? Carbon::parse($original) : null,
                $transaction->post_date,
            );
        });

        static::deleting(function (self $transaction) {
            $transaction->guardFrozenPeriod($transaction->post_date);
        });

        // Keep the cached journal balance in sync. In the default
        // on_commit mode the recompute is batched per journal and runs
        // just before the surrounding transaction commits; in immediate
        // mode (or outside any transaction) it runs right here.
        static::saved(function (self $transaction) {
            app(PendingBalanceUpdates::class)->record($transaction->journal);
        });

        static::deleted(function (self $transaction) {
            app(PendingBalanceUpdates::class)->record($transaction->journal);
        });
    }

    /**
     * Wrap the save in a transaction so the frozen-period guard's locked
     * read of the journal row and the write it protects commit
     * atomically. Laravel nests transactions via savepoints, so a save
     * already inside an outer transaction (e.g. TransactionGroup::commit())
     * is unaffected.
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    /**
     * Wrap the delete in a transaction; see save() above.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(app(JournalModels::class)->journal());
    }

    /**
     * Any model this entry references.
     *
     * To associate: $transaction->reference()->associate($model)->save();
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Reject any write whose post date falls in the journal's closed
     * period. The lock is the journal's latest checkpoint date; correct
     * a closed period with an adjusting entry in the open period, or by
     * removing checkpoints first.
     *
     * Takes a lock on the journal row before reading locked_until, so
     * this read cannot interleave with a concurrent checkpoint() (which
     * takes the same row lock before summing and freezing). One locked
     * read covers every date passed in, so an update's before-and-after
     * post dates are checked against a single consistent journal read.
     *
     * @throws PeriodClosed
     */
    protected function guardFrozenPeriod(?CarbonInterface ...$postDates): void
    {
        $postDates = array_filter($postDates, fn (?CarbonInterface $date): bool => $date !== null);

        if ($postDates === []) {
            return;
        }

        $journal = $this->journal()->lockForUpdate()->first();

        if ($journal !== null) {
            // Reuse this fresh, locked instance for the rest of the
            // lifecycle (e.g. the saved/deleted hook's resetCurrentBalance())
            // instead of triggering another query.
            $this->setRelation('journal', $journal);
        }

        $lockedUntil = $journal?->locked_until;

        if ($lockedUntil === null) {
            return;
        }

        foreach ($postDates as $postDate) {
            if ($postDate->lessThanOrEqualTo($lockedUntil->copy()->endOfDay())) {
                throw new PeriodClosed(sprintf(
                    'Journal %d is closed through %s; cannot post, change, or delete a transaction dated %s.',
                    $journal->id,
                    $lockedUntil->toDateString(),
                    $postDate->toDateString(),
                ));
            }
        }
    }

    /**
     * The signed amount: credit as positive, debit as negative.
     */
    public function getAmountAttribute(): Money
    {
        if (($this->attributes['credit'] ?? null) !== null) {
            return $this->credit;
        }

        if (($this->attributes['debit'] ?? null) !== null) {
            return $this->debit->multiply(-1);
        }

        return new Money(0, $this->currency);
    }
}

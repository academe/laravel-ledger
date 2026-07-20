<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Casts\LedgerTypeCast;
use Academe\LaravelJournal\Contracts\LedgerType;
use Academe\LaravelJournal\Enums\BalanceSide;
use Academe\LaravelJournal\JournalModels;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Money\Currency;
use Money\Money;

/**
 * A ledger groups journals under an account type: one of the five
 * standard elements, or an application-registered LedgerType enum.
 *
 * @property string $name
 * @property LedgerType $type
 */
class Ledger extends Model
{
    protected $table = 'journal_ledgers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerTypeCast::class,
        ];
    }

    /**
     * @return HasMany<Journal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(app(JournalModels::class)->journal(), 'ledger_id');
    }

    /**
     * @return HasManyThrough<JournalTransaction, Journal, $this>
     */
    public function journalTransactions(): HasManyThrough
    {
        $models = app(JournalModels::class);

        return $this->hasManyThrough(
            $models->transaction(),
            $models->journal(),
            'ledger_id',
            'journal_id',
        );
    }

    /**
     * The normal balance at the end of the given day: transactions in
     * the given currency across the ledger's journals, dated up to the
     * end of that day, signed from the ledger type's normal balance
     * side. A null date means now, excluding future-dated transactions.
     *
     * Debit-normal ledger types (asset, expense) report debit - credit;
     * credit-normal types (liability, equity, income) report
     * credit - debit.
     */
    public function normalBalanceOn(Currency|string $currency, ?CarbonInterface $date = null): Money
    {
        return $this->balanceThrough($currency, ($date ?? Carbon::now())->copy()->endOfDay());
    }

    /**
     * The normal balance across all transactions, including future-dated
     * ones: the ledger analogue of Journal::totalBalance(), signed from
     * the ledger type's normal balance side as in normalBalanceOn().
     */
    public function normalTotalBalance(Currency|string $currency): Money
    {
        return $this->balanceThrough($currency, null);
    }

    /**
     * @deprecated use normalTotalBalance() for this all-time sum, or
     *             normalBalanceOn() for a date-bounded normal balance
     */
    public function currentBalance(Currency|string $currency): Money
    {
        return $this->normalTotalBalance($currency);
    }

    /**
     * Sum the ledger's transactions in the given currency up to an
     * instant (null means all-time), signed from the ledger type's
     * normal balance side.
     */
    protected function balanceThrough(Currency|string $currency, ?CarbonInterface $through): Money
    {
        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        $sum = function (string $column) use ($currency, $through): Money {
            $query = $this->journalTransactions()
                ->where('journal_transactions.currency_code', $currency->getCode());

            if ($through !== null) {
                $query->where('post_date', '<=', $through);
            }

            return new Money((int) $query->sum($column), $currency);
        };

        $debit = $sum('debit');
        $credit = $sum('credit');

        return match ($this->type->normalBalance()) {
            BalanceSide::Debit => $debit->subtract($credit),
            BalanceSide::Credit => $credit->subtract($debit),
        };
    }

    /**
     * Checkpoint every journal in this ledger at the given date, in one
     * database transaction: if any member journal fails (for example it
     * already has a later checkpoint), the whole operation rolls back.
     *
     * This is a bulk convenience over Journal::checkpoint(); ledgers
     * hold no checkpoint data of their own. Journals are iterated in id
     * order, a documented, production-safe contract callers can rely on.
     *
     * @return int the number of journals checkpointed
     */
    public function checkpoint(CarbonInterface|string $date): int
    {
        return DB::transaction(function () use ($date): int {
            $count = 0;

            foreach ($this->journals()->orderBy('id')->get() as $journal) {
                $journal->checkpoint($date);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Remove checkpoints dated on or after the given date from every
     * journal in this ledger, in one database transaction. Journals are
     * iterated in id order, a documented, production-safe contract
     * callers can rely on.
     *
     * If the range reaches any member journal's opening-balance
     * checkpoint, that journal throws CheckpointNotRemovable and the
     * whole bulk removal rolls back.
     *
     * @return int the total number of checkpoints removed
     */
    public function removeCheckpointsSince(CarbonInterface|string $date): int
    {
        return DB::transaction(function () use ($date): int {
            $removed = 0;

            foreach ($this->journals()->orderBy('id')->get() as $journal) {
                $removed += $journal->removeCheckpointsSince($date);
            }

            return $removed;
        });
    }
}

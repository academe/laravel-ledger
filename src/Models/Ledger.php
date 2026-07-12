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
     * Sum all transactions in the given currency across the ledger's
     * journals. Debit-normal ledger types (asset, expense) report
     * debit - credit; credit-normal types (liability, equity, income)
     * report credit - debit.
     */
    public function currentBalance(Currency|string $currency): Money
    {
        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        $debit = new Money(
            (int) $this->journalTransactions()
                ->where('journal_transactions.currency_code', $currency->getCode())
                ->sum('debit'),
            $currency,
        );

        $credit = new Money(
            (int) $this->journalTransactions()
                ->where('journal_transactions.currency_code', $currency->getCode())
                ->sum('credit'),
            $currency,
        );

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

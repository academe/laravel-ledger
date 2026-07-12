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

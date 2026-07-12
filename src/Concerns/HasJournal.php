<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Concerns;

use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\JournalModels;
use Academe\LaravelJournal\Models\Journal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Money\Currency;
use Money\Money;

/**
 * For models that own an accounting journal.
 *
 * @mixin Model
 *
 * @property Journal|null $journal
 */
trait HasJournal
{
    /**
     * @return MorphOne<Journal, $this>
     */
    public function journal(): MorphOne
    {
        return $this->morphOne(app(JournalModels::class)->journal(), 'owner');
    }

    /**
     * Initialise a journal for this model instance.
     *
     * @throws JournalAlreadyExists
     */
    public function initJournal(
        Currency|string|null $currency = null,
        ?int $ledgerId = null,
    ): Journal {
        if ($this->journal()->exists()) {
            throw new JournalAlreadyExists;
        }

        $currency ??= config('journal.base_currency');

        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        $journalClass = app(JournalModels::class)->journal();

        $journal = new $journalClass;

        $journal->ledger_id = $ledgerId;
        $journal->balance = new Money(0, $currency);

        $this->journal()->save($journal);

        return $journal;
    }
}

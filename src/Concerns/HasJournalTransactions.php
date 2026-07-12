<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Concerns;

use Academe\LaravelJournal\JournalModels;
use Academe\LaravelJournal\Models\JournalTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For models that journal transactions may reference.
 *
 * @mixin Model
 *
 * @property Collection<int, JournalTransaction> $journalTransactions
 */
trait HasJournalTransactions
{
    /**
     * @return MorphMany<JournalTransaction, $this>
     */
    public function journalTransactions(): MorphMany
    {
        return $this->morphMany(app(JournalModels::class)->transaction(), 'reference');
    }
}

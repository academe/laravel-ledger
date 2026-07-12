<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Academe\LaravelJournal\Exceptions\InvalidJournalModel;
use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Models\JournalCheckpoint;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Models\Ledger;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the model classes the package should use, honouring
 * the overrides in config('journal.models').
 *
 * Registered as a container singleton; swap it in the container
 * to change resolution wholesale.
 */
class JournalModels
{
    /**
     * @return class-string<Ledger>
     */
    public function ledger(): string
    {
        return $this->resolve('ledger', Ledger::class);
    }

    /**
     * @return class-string<Journal>
     */
    public function journal(): string
    {
        return $this->resolve('journal', Journal::class);
    }

    /**
     * @return class-string<JournalTransaction>
     */
    public function transaction(): string
    {
        return $this->resolve('transaction', JournalTransaction::class);
    }

    /**
     * @return class-string<JournalCheckpoint>
     */
    public function checkpoint(): string
    {
        return $this->resolve('checkpoint', JournalCheckpoint::class);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $base
     * @return class-string<TModel>
     *
     * @throws InvalidJournalModel
     */
    protected function resolve(string $key, string $base): string
    {
        $class = config("journal.models.{$key}", $base);

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new InvalidJournalModel(sprintf(
                'Configured journal.models.%s [%s] must be %s or a subclass of it.',
                $key,
                is_string($class) ? $class : get_debug_type($class),
                $base,
            ));
        }

        return $class;
    }
}

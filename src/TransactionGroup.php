<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;
use Academe\LaravelJournal\Models\Journal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Money\Money;
use Throwable;

/**
 * Builds a balanced double-entry transaction group and commits it
 * atomically. All entries share a group UUID.
 */
class TransactionGroup
{
    /**
     * @var array<int, array{
     *     journal: Journal,
     *     method: string,
     *     money: Money,
     *     memo: string|null,
     *     reference: Model|null,
     *     postDate: CarbonInterface|null,
     * }>
     */
    protected array $pending = [];

    /**
     * The constructor is final so that `new static()` in make() is safe:
     * subclasses cannot declare an incompatible constructor.
     */
    final public function __construct() {}

    /**
     * Create a new TransactionGroup instance.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Queue a credit or debit against a journal.
     *
     * @throws InvalidJournalMethod
     * @throws InvalidJournalEntryValue
     */
    public function addTransaction(
        Journal $journal,
        string $method,
        Money $money,
        ?string $memo = null,
        ?Model $reference = null,
        ?CarbonInterface $postDate = null,
    ): static {
        if (! in_array($method, ['credit', 'debit'], true)) {
            throw new InvalidJournalMethod;
        }

        if ($money->isZero() || $money->isNegative()) {
            throw new InvalidJournalEntryValue;
        }

        $this->pending[] = [
            'journal' => $journal,
            'method' => $method,
            'money' => $money,
            'memo' => $memo,
            'reference' => $reference,
            'postDate' => $postDate,
        ];

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Commit all pending entries atomically.
     *
     * On an empty group (no queued entries), this is a no-op: it still
     * returns a freshly generated UUID, but nothing is written to the
     * database.
     *
     * @return string the shared transaction group UUID
     *
     * @throws DebitsAndCreditsDoNotEqual
     * @throws TransactionCouldNotBeProcessed
     */
    public function commit(): string
    {
        $this->assertCreditsEqualDebits();

        try {
            return DB::transaction(function (): string {
                $groupUuid = (string) Str::orderedUuid();

                foreach ($this->pending as $entry) {
                    $transaction = $entry['journal']->{$entry['method']}(
                        $entry['money'],
                        $entry['memo'],
                        $entry['postDate'],
                        $groupUuid,
                    );

                    if ($entry['reference'] !== null) {
                        $transaction->reference()->associate($entry['reference'])->save();
                    }
                }

                return $groupUuid;
            });
        } catch (Throwable $e) {
            throw new TransactionCouldNotBeProcessed(previous: $e);
        }
    }

    /**
     * Credits must equal debits within each currency present in the group;
     * amounts in different currencies are never summed together.
     *
     * @throws DebitsAndCreditsDoNotEqual
     */
    protected function assertCreditsEqualDebits(): void
    {
        $credits = [];
        $debits = [];

        foreach ($this->pending as $entry) {
            $currencyCode = $entry['money']->getCurrency()->getCode();

            if ($entry['method'] === 'credit') {
                $credits[$currencyCode] = ($credits[$currencyCode] ?? 0) + (int) $entry['money']->getAmount();
            } else {
                $debits[$currencyCode] = ($debits[$currencyCode] ?? 0) + (int) $entry['money']->getAmount();
            }
        }

        $currencyCodes = array_unique([...array_keys($credits), ...array_keys($debits)]);

        foreach ($currencyCodes as $currencyCode) {
            $currencyCredits = $credits[$currencyCode] ?? 0;
            $currencyDebits = $debits[$currencyCode] ?? 0;

            if ($currencyCredits !== $currencyDebits) {
                throw new DebitsAndCreditsDoNotEqual(
                    "In this transaction, {$currencyCode} credits == {$currencyCredits} and {$currencyCode} debits == {$currencyDebits}.",
                );
            }
        }
    }
}

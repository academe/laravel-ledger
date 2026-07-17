<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use Academe\LaravelJournal\Models\Journal;
use Carbon\CarbonInterface;

/**
 * A write touched a journal's closed (checkpointed) period.
 *
 * Carries the facts as structured properties so applications can phrase
 * their own user-facing messages instead of parsing this one.
 */
class PeriodClosed extends JournalException
{
    public function __construct(
        public readonly Journal $journal,
        public readonly CarbonInterface $lockedUntil,
        public readonly CarbonInterface $postDate,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Journal "%s" is closed through %s; cannot post, change, or delete a transaction dated %s.',
            $journal->displayName(),
            $lockedUntil->toDateString(),
            $postDate->toDateString(),
        ));
    }
}

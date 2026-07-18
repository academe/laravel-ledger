<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use Money\Currency;

/**
 * A posted amount's currency differs from the journal's currency.
 *
 * Carries both currencies as structured properties so applications can
 * phrase their own messages instead of parsing this one.
 */
class CurrencyMismatch extends JournalRuntimeException
{
    public function __construct(
        public readonly Currency $amountCurrency,
        public readonly Currency $journalCurrency,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Amount currency %s does not match journal currency %s.',
            $amountCurrency->getCode(),
            $journalCurrency->getCode(),
        ));
    }
}

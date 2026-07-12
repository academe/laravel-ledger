<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use Throwable;

class TransactionCouldNotBeProcessed extends JournalException
{
    public function __construct(
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? 'Double-entry transaction group could not be processed.',
            0,
            $previous,
        );
    }
}

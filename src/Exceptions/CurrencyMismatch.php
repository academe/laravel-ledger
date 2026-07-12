<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class CurrencyMismatch extends JournalException
{
    public function __construct(string $message = 'Amount currency does not match the journal currency.')
    {
        parent::__construct($message);
    }
}

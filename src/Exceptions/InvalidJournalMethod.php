<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class InvalidJournalMethod extends JournalLogicException
{
    public function __construct(string $message = 'Journal methods must be credit or debit.')
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class InvalidJournalEntryValue extends JournalException
{
    public function __construct(string $message = 'Journal transaction entries must be a positive value.')
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class PeriodClosed extends JournalException
{
    public function __construct(string $message = 'The accounting period for this journal is closed.')
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class DebitsAndCreditsDoNotEqual extends JournalException
{
    public function __construct(?string $detail = null)
    {
        $message = 'Double entry requires that debits equal credits.';

        if ($detail !== null) {
            $message .= ' '.$detail;
        }

        parent::__construct($message);
    }
}

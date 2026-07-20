<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class JournalNotInLedger extends JournalLogicException
{
    public function __construct(string $message = 'Journal is not assigned to a ledger, so it has no normal balance side.')
    {
        parent::__construct($message);
    }
}

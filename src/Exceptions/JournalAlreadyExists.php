<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class JournalAlreadyExists extends JournalRuntimeException
{
    public function __construct(string $message = 'Journal already exists for this model.')
    {
        parent::__construct($message);
    }
}

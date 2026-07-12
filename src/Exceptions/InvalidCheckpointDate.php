<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class InvalidCheckpointDate extends JournalException
{
    public function __construct(string $message = 'Checkpoint date must be after the latest existing checkpoint.')
    {
        parent::__construct($message);
    }
}

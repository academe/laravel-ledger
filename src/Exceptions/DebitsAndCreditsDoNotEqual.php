<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

/**
 * An unbalanced group is one kind of commit failure, so this extends
 * TransactionCouldNotBeProcessed: catching the wrapper covers every way
 * commit() can fail. Unlike the wrapped causes, it carries no previous
 * exception — the group never reached the database.
 */
class DebitsAndCreditsDoNotEqual extends TransactionCouldNotBeProcessed
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

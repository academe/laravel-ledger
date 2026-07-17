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
        if ($message === null) {
            $message = 'Double-entry transaction group could not be processed.';

            // Surface the cause in the message so plain logging of the
            // wrapper stays informative; getPrevious() remains the
            // structured route.
            if ($previous !== null && $previous->getMessage() !== '') {
                $message = 'Double-entry transaction group could not be processed: '
                    .$previous->getMessage();
            }
        }

        parent::__construct($message, 0, $previous);
    }
}

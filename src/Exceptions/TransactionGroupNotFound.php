<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class TransactionGroupNotFound extends JournalRuntimeException
{
    public function __construct(
        public readonly string $transactionGroup,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'No transactions found for transaction group %s.',
            $transactionGroup,
        ));
    }
}

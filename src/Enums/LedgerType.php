<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Enums;

/**
 * General ledger account types.
 */
enum LedgerType: string
{
    // Debit accounts: balance reported as debit - credit.
    case ASSET = 'asset';
    case EXPENSE = 'expense';

    // Credit accounts: balance reported as credit - debit.
    case LIABILITY = 'liability';
    case EQUITY = 'equity'; // aka capital
    case INCOME = 'income'; // aka revenue
}

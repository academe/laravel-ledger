<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Enums;

use Academe\LaravelJournal\Contracts\LedgerType;

/**
 * The five elements of the accounting equation. These are universal
 * across UK GAAP, IFRS, and US GAAP; jurisdictional differences live in
 * the chart of accounts and presentation, not here.
 */
enum StandardLedgerType: string implements LedgerType
{
    // Assets — resources owned (cash, debtors/receivables, stock/inventory).
    case ASSET = 'asset';
    // Expenses — costs / overheads.
    case EXPENSE = 'expense';

    // Liabilities — amounts owed (creditors/payables, loans).
    case LIABILITY = 'liability';
    // Equity — owner's capital / shareholders' funds.
    case EQUITY = 'equity';
    // Income — revenue / turnover, plus gains.
    case INCOME = 'income';

    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::ASSET, self::EXPENSE => BalanceSide::Debit,
            self::LIABILITY, self::EQUITY, self::INCOME => BalanceSide::Credit,
        };
    }
}

<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Enums;

/**
 * The side a ledger type's balance normally sits on, and therefore how
 * its balance is reported: debit-normal ledgers report debit - credit,
 * credit-normal ledgers report credit - debit.
 */
enum BalanceSide
{
    case Debit;
    case Credit;
}

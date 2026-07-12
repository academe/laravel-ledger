<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Contracts;

use Academe\LaravelJournal\Enums\BalanceSide;
use BackedEnum;

/**
 * A ledger account type. The one behavioural fact balance arithmetic
 * depends on is which side the type's balance normally sits on.
 *
 * Implement this on a string-backed enum (extending BackedEnum means
 * only enums can implement it); the backing value is the code stored in
 * the ledgers table. Register the enum class in the
 * journal.ledger_types config array so the cast can resolve stored
 * codes back to cases. StandardLedgerType ships the five elements of
 * the accounting equation; add your own enum for extensions such as
 * contra-accounts.
 */
interface LedgerType extends BackedEnum
{
    public function normalBalance(): BalanceSide;
}

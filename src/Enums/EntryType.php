<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Enums;

/**
 * The side a journal entry posts to.
 *
 * Distinct from BalanceSide, which is about how a ledger type's balance
 * is reported; this is about a single entry's direction. The backing
 * values match the Journal::credit() / Journal::debit() method names
 * and the strings TransactionGroup::addTransaction() has always
 * accepted.
 */
enum EntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}

<?php

declare(strict_types=1);

use Academe\LaravelJournal\Enums\EntryType;
use Academe\LaravelJournal\Models\Ledger;
use Academe\LaravelJournal\Tests\Fixtures\Models\CompanyJournal;
use Academe\LaravelJournal\TransactionGroup;
use Money\Money;

/**
 * A narrative, end-to-end walk through one month of a small company's
 * books, exercising every standard ledger type through double-entry
 * transaction groups and proving the accounting equation
 *
 *     assets = liabilities + equity + (income - expenses)
 *
 * holds after every event, and in its closed form
 * (assets = liabilities + equity) after the period-end closing entries.
 */

/**
 * The accounting equation, from the ledger balances.
 */
function expectBooksToBalance(object $books): void
{
    $assets = $books->assets->currentBalance('GBP');
    $liabilities = $books->liabilities->currentBalance('GBP');
    $equity = $books->equity->currentBalance('GBP');
    $income = $books->income->currentBalance('GBP');
    $expenses = $books->expenses->currentBalance('GBP');

    expect($assets)->toEqual(
        $liabilities->add($equity)->add($income)->subtract($expenses),
    );
}

/**
 * Five ledgers and the month's chart of account journals, all in GBP.
 */
function tradingCompany(): object
{
    $ledgers = (object) [
        'assets' => Ledger::create(['name' => 'Assets', 'type' => 'asset']),
        'liabilities' => Ledger::create(['name' => 'Liabilities', 'type' => 'liability']),
        'equity' => Ledger::create(['name' => 'Equity', 'type' => 'equity']),
        'income' => Ledger::create(['name' => 'Income', 'type' => 'income']),
        'expenses' => Ledger::create(['name' => 'Expenses', 'type' => 'expense']),
    ];

    $journal = fn (string $name, Ledger $ledger) => CompanyJournal::create(['name' => $name])
        ->initJournal('GBP')
        ->assignToLedger($ledger);

    $ledgers->cash = $journal('Cash', $ledgers->assets);
    $ledgers->receivables = $journal('Accounts Receivable', $ledgers->assets);
    $ledgers->equipment = $journal('Equipment', $ledgers->assets);
    $ledgers->loan = $journal('Bank Loan', $ledgers->liabilities);
    $ledgers->shareCapital = $journal('Share Capital', $ledgers->equity);
    $ledgers->retainedEarnings = $journal('Retained Earnings', $ledgers->equity);
    $ledgers->sales = $journal('Sales', $ledgers->income);
    $ledgers->rent = $journal('Rent', $ledgers->expenses);
    $ledgers->wages = $journal('Wages', $ledgers->expenses);

    return $ledgers;
}

it('keeps the accounting equation balanced through a month of trading', function () {
    $books = tradingCompany();

    // The owner invests £10,000 of share capital.
    TransactionGroup::make()
        ->addTransaction($books->cash, EntryType::Debit, Money::GBP(1_000_000), 'Owner investment')
        ->addTransaction($books->shareCapital, EntryType::Credit, Money::GBP(1_000_000), 'Owner investment')
        ->commit();

    expectBooksToBalance($books);

    // The bank lends £5,000.
    TransactionGroup::make()
        ->addTransaction($books->cash, EntryType::Debit, Money::GBP(500_000), 'Bank loan advanced')
        ->addTransaction($books->loan, EntryType::Credit, Money::GBP(500_000), 'Bank loan advanced')
        ->commit();

    expectBooksToBalance($books);

    // £3,000 of equipment is bought for cash: one asset becomes another.
    TransactionGroup::make()
        ->addTransaction($books->equipment, EntryType::Debit, Money::GBP(300_000), 'Workshop equipment')
        ->addTransaction($books->cash, EntryType::Credit, Money::GBP(300_000), 'Workshop equipment')
        ->commit();

    expectBooksToBalance($books);

    // A client is invoiced £2,500: income earned, not yet received.
    TransactionGroup::make()
        ->addTransaction($books->receivables, EntryType::Debit, Money::GBP(250_000), 'Invoice #1')
        ->addTransaction($books->sales, EntryType::Credit, Money::GBP(250_000), 'Invoice #1')
        ->commit();

    expectBooksToBalance($books);

    // The client pays £1,500 of the invoice.
    TransactionGroup::make()
        ->addTransaction($books->cash, EntryType::Debit, Money::GBP(150_000), 'Invoice #1 part payment')
        ->addTransaction($books->receivables, EntryType::Credit, Money::GBP(150_000), 'Invoice #1 part payment')
        ->commit();

    // Rent of £800 and wages of £1,200 are paid in cash.
    TransactionGroup::make()
        ->addTransaction($books->rent, EntryType::Debit, Money::GBP(80_000), 'March rent')
        ->addTransaction($books->wages, EntryType::Debit, Money::GBP(120_000), 'March wages')
        ->addTransaction($books->cash, EntryType::Credit, Money::GBP(200_000), 'March rent and wages')
        ->commit();

    expectBooksToBalance($books);

    // £500 of the loan is repaid.
    TransactionGroup::make()
        ->addTransaction($books->loan, EntryType::Debit, Money::GBP(50_000), 'Loan repayment')
        ->addTransaction($books->cash, EntryType::Credit, Money::GBP(50_000), 'Loan repayment')
        ->commit();

    expectBooksToBalance($books);

    // The month's position, journal by journal. A journal's own balance
    // is signed credit-positive, so the debit-heavy asset journals read
    // negative here; the ledger balances below sign by normal side.
    expect($books->cash->currentBalance())->toEqual(Money::GBP(-1_100_000))
        ->and($books->receivables->currentBalance())->toEqual(Money::GBP(-100_000))
        ->and($books->equipment->currentBalance())->toEqual(Money::GBP(-300_000))
        ->and($books->loan->currentBalance())->toEqual(Money::GBP(450_000))
        ->and($books->shareCapital->currentBalance())->toEqual(Money::GBP(1_000_000))
        ->and($books->sales->currentBalance())->toEqual(Money::GBP(250_000));

    // ...and ledger by ledger, signed by each type's normal balance side.
    expect($books->assets->currentBalance('GBP'))->toEqual(Money::GBP(1_500_000))
        ->and($books->liabilities->currentBalance('GBP'))->toEqual(Money::GBP(450_000))
        ->and($books->equity->currentBalance('GBP'))->toEqual(Money::GBP(1_000_000))
        ->and($books->income->currentBalance('GBP'))->toEqual(Money::GBP(250_000))
        ->and($books->expenses->currentBalance('GBP'))->toEqual(Money::GBP(200_000));

    // Period end: close income and expenses to retained earnings.
    TransactionGroup::make()
        ->addTransaction($books->sales, EntryType::Debit, Money::GBP(250_000), 'Close income to retained earnings')
        ->addTransaction($books->retainedEarnings, EntryType::Credit, Money::GBP(250_000), 'Close income to retained earnings')
        ->commit();

    TransactionGroup::make()
        ->addTransaction($books->retainedEarnings, EntryType::Debit, Money::GBP(200_000), 'Close expenses to retained earnings')
        ->addTransaction($books->rent, EntryType::Credit, Money::GBP(80_000), 'Close March rent')
        ->addTransaction($books->wages, EntryType::Credit, Money::GBP(120_000), 'Close March wages')
        ->commit();

    // Income and expenses now stand at zero, the £500 profit sits in
    // equity, and the closed-form equation holds:
    // assets = liabilities + equity.
    expect($books->income->currentBalance('GBP'))->toEqual(Money::GBP(0))
        ->and($books->expenses->currentBalance('GBP'))->toEqual(Money::GBP(0))
        ->and($books->retainedEarnings->currentBalance())->toEqual(Money::GBP(50_000))
        ->and($books->equity->currentBalance('GBP'))->toEqual(Money::GBP(1_050_000))
        ->and($books->assets->currentBalance('GBP'))->toEqual(
            $books->liabilities->currentBalance('GBP')
                ->add($books->equity->currentBalance('GBP')),
        );

    expectBooksToBalance($books);
});

<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Models\Ledger;
use Academe\LaravelJournal\Tests\Fixtures\Models\CompanyJournal;
use Academe\LaravelJournal\Tests\Fixtures\Models\User;
use Academe\LaravelJournal\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class)->in('Unit');
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

function makeUserJournal(string $currencyCode = 'USD'): Journal
{
    $user = User::create([
        'name' => 'Test User',
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);

    return Journal::create([
        'currency_code' => $currencyCode,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);
}

function fixtureUser(string $email = 'user@example.com'): User
{
    return User::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => 'secret',
    ]);
}

/**
 * The standard five ledgers plus company AR/cash/income journals,
 * mirroring the original package's BaseTest setup.
 */
function companyBooks(string $currencyCode = 'USD'): object
{
    $assetsLedger = Ledger::create(['name' => 'Company Assets', 'type' => 'asset']);
    $liabilityLedger = Ledger::create(['name' => 'Company Liabilities', 'type' => 'liability']);
    $equityLedger = Ledger::create(['name' => 'Company Equity', 'type' => 'equity']);
    $incomeLedger = Ledger::create(['name' => 'Company Income', 'type' => 'income']);
    $expenseLedger = Ledger::create(['name' => 'Company Expenses', 'type' => 'expense']);

    $arJournal = CompanyJournal::create(['name' => 'Accounts Receivable'])
        ->initJournal($currencyCode)
        ->assignToLedger($assetsLedger);

    $cashJournal = CompanyJournal::create(['name' => 'Cash'])
        ->initJournal($currencyCode)
        ->assignToLedger($assetsLedger);

    $incomeJournal = CompanyJournal::create(['name' => 'Company Income'])
        ->initJournal($currencyCode)
        ->assignToLedger($incomeLedger);

    return (object) compact(
        'assetsLedger',
        'liabilityLedger',
        'equityLedger',
        'incomeLedger',
        'expenseLedger',
        'arJournal',
        'cashJournal',
        'incomeJournal',
    );
}

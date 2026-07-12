<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the package tables', function () {
    expect(Schema::hasTable('journal_ledgers'))->toBeTrue();
    expect(Schema::hasTable('journals'))->toBeTrue();
    expect(Schema::hasTable('journal_transactions'))->toBeTrue();
});

it('creates the journal columns', function () {
    expect(Schema::hasColumns('journals', [
        'id', 'ledger_id', 'balance', 'currency_code', 'owner_type', 'owner_id',
    ]))->toBeTrue();
});

it('creates the transaction columns', function () {
    expect(Schema::hasColumns('journal_transactions', [
        'id', 'transaction_group', 'journal_id', 'debit', 'credit',
        'currency_code', 'memo', 'tags', 'reference_type', 'reference_id',
        'post_date', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the fixture tables', function () {
    expect(Schema::hasTable('users'))->toBeTrue();
    expect(Schema::hasTable('accounts'))->toBeTrue();
    expect(Schema::hasTable('products'))->toBeTrue();
    expect(Schema::hasTable('company_journals'))->toBeTrue();
});

it('creates the journal_checkpoints table', function () {
    expect(Schema::hasTable('journal_checkpoints'))->toBeTrue();
    expect(Schema::hasColumns('journal_checkpoints', [
        'id', 'journal_id', 'checkpoint_date', 'debit_total', 'credit_total', 'currency_code',
    ]))->toBeTrue();
});

it('adds locked_until to journals', function () {
    expect(Schema::hasColumn('journals', 'locked_until'))->toBeTrue();
});

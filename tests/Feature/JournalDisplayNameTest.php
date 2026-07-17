<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Tests\Fixtures\Models\Account;
use Academe\LaravelJournal\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

it('uses the owner journalDisplayName when the owner implements NamesJournal', function () {
    $account = Account::create(['name' => 'VAT owed']);
    $journal = $account->initJournal('USD');

    expect($journal->displayName())->toBe('VAT owed');
});

it('falls back to class basename and owner id for FQCN owner types', function () {
    $journal = makeUserJournal();

    expect($journal->displayName())->toBe('User #'.$journal->owner_id);
});

it('falls back to the morph alias and owner id when the app maps one', function () {
    Relation::morphMap(['user' => User::class]);

    try {
        $user = fixtureUser();
        $journal = Journal::create([
            'currency_code' => 'USD',
            'owner_type' => $user->getMorphClass(), // 'user'
            'owner_id' => $user->id,
        ]);

        expect($journal->displayName())->toBe('user #'.$user->id);
    } finally {
        Relation::morphMap([], false);
    }
});

it('falls back to journal id when the owner row is missing', function () {
    $journal = Journal::create([
        'currency_code' => 'USD',
        'owner_type' => User::class,
        'owner_id' => 999,
    ]);

    expect($journal->displayName())->toBe('journal #'.$journal->id);
});

it('falls back to journal id when the owner type is unloadable', function () {
    $journal = Journal::create([
        'currency_code' => 'USD',
        'owner_type' => 'unregistered_alias',
        'owner_id' => 1,
    ]);

    expect($journal->displayName())->toBe('journal #'.$journal->id);
});

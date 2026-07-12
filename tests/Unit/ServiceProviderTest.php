<?php

declare(strict_types=1);

it('merges the package config', function () {
    expect(config('journal.base_currency'))->toBe('GBP');
    expect(config('journal.models.ledger'))->toBe('Academe\LaravelJournal\Models\Ledger');
    expect(config('journal.models.journal'))->toBe('Academe\LaravelJournal\Models\Journal');
    expect(config('journal.models.transaction'))->toBe('Academe\LaravelJournal\Models\JournalTransaction');
});

it('registers the checkpoint model in config', function () {
    expect(config('journal.models.checkpoint'))
        ->toBe('Academe\LaravelJournal\Models\JournalCheckpoint');
});

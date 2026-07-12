<?php

declare(strict_types=1);

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
use Academe\LaravelJournal\Casts\TagsCast;
use Academe\LaravelJournal\Exceptions\InvalidTags;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;
use Money\Money;

$model = fn () => new class extends Model {};

it('gets a Currency from the configured column', function () use ($model) {
    $cast = new CurrencyCast('currency_code');

    $currency = $cast->get($model(), 'currency', null, ['currency_code' => 'USD']);

    expect($currency)->toEqual(new Currency('USD'));
});

it('gets null Currency when the column is empty', function () use ($model) {
    $cast = new CurrencyCast('currency_code');

    expect($cast->get($model(), 'currency', null, ['currency_code' => null]))->toBeNull();
});

it('sets a Currency into the configured column', function () use ($model) {
    $cast = new CurrencyCast('currency_code');

    expect($cast->set($model(), 'currency', new Currency('GBP'), []))
        ->toBe(['currency_code' => 'GBP']);
});

it('sets null Currency as null', function () use ($model) {
    $cast = new CurrencyCast('currency_code');

    expect($cast->set($model(), 'currency', null, []))
        ->toBe(['currency_code' => null]);
});

it('gets Money from amount and currency columns', function () use ($model) {
    $cast = new MoneyCast('currency_code', 'balance');

    $money = $cast->get($model(), 'balance', null, [
        'currency_code' => 'USD',
        'balance' => 1234,
    ]);

    expect($money)->toEqual(new Money(1234, new Currency('USD')));
});

it('gets null Money when either column is missing', function () use ($model) {
    $cast = new MoneyCast('currency_code', 'balance');

    expect($cast->get($model(), 'balance', null, ['currency_code' => 'USD', 'balance' => null]))->toBeNull();
    expect($cast->get($model(), 'balance', null, ['currency_code' => null, 'balance' => 100]))->toBeNull();
});

it('sets Money into amount and currency columns', function () use ($model) {
    $cast = new MoneyCast('currency_code', 'credit');

    expect($cast->set($model(), 'credit', new Money(500, new Currency('EUR')), []))
        ->toBe(['currency_code' => 'EUR', 'credit' => '500']);
});

it('sets null Money as nulls in both columns', function () use ($model) {
    $cast = new MoneyCast('currency_code', 'credit');

    expect($cast->set($model(), 'credit', null, []))
        ->toBe(['currency_code' => null, 'credit' => null]);
});

it('falls back to key-derived column names', function () use ($model) {
    $cast = new MoneyCast;

    $money = $cast->get($model(), 'price', null, [
        'price_currency' => 'USD',
        'price_amount' => 42,
    ]);

    expect($money)->toEqual(new Money(42, new Currency('USD')));
});

it('round-trips string-keyed scalar tags', function () use ($model) {
    $cast = new TagsCast;
    $tags = ['status' => 'paid', 'attempts' => 3, 'express' => true, 'rate' => 0.2];

    $stored = $cast->set($model(), 'tags', $tags, []);

    expect($stored)->toBeString();
    expect($cast->get($model(), 'tags', $stored, []))->toBe($tags);
});

it('reads a stored null as an empty tag map', function () use ($model) {
    expect((new TagsCast)->get($model(), 'tags', null, []))->toBe([]);
});

it('stores an empty tag map as null', function () use ($model) {
    $cast = new TagsCast;

    expect($cast->set($model(), 'tags', [], []))->toBeNull();
    expect($cast->set($model(), 'tags', null, []))->toBeNull();
});

it('rejects tags that are not an array', function () use ($model) {
    (new TagsCast)->set($model(), 'tags', 'paid', []);
})->throws(InvalidTags::class, 'string given');

it('rejects a tag list with integer keys', function () use ($model) {
    (new TagsCast)->set($model(), 'tags', ['paid', 'express'], []);
})->throws(InvalidTags::class, 'must be strings');

it('rejects nested tag values', function () use ($model) {
    (new TagsCast)->set($model(), 'tags', ['status' => ['paid' => true]], []);
})->throws(InvalidTags::class, "Tag 'status'");

it('drops malformed entries when reading hand-written tag rows', function () use ($model) {
    $cast = new TagsCast;

    expect($cast->get($model(), 'tags', '{"status":"paid","meta":{"a":1}}', []))
        ->toBe(['status' => 'paid']);
    expect($cast->get($model(), 'tags', '"not a map"', []))->toBe([]);
});

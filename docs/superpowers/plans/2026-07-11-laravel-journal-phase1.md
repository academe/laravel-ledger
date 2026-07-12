# academe/laravel-journal Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert consilience/accounting into the modernised `academe/laravel-journal` package for Laravel 12, preserving behaviour while resolving the fork's outstanding `@todo`s.

**Architecture:** A standalone Composer package. Three Eloquent models (`Journal`, `JournalTransaction`, `Ledger`) store money as integer minor units with credit/debit in separate columns; two traits attach journals/transaction-references to host models; a `TransactionGroup` builder enforces balanced double-entry commits inside a DB transaction. Journal balances are cached on the `journals.balance` column, recomputed on every transaction save/delete.

**Tech Stack:** PHP ^8.2, Laravel (illuminate/*) ^12.0, moneyphp/money ^4.0, Pest ^3 on Orchestra Testbench ^10, Larastan ^3, Pint.

**Spec:** `docs/superpowers/specs/2026-07-11-laravel-journal-conversion-design.md`
**Reference source:** the fork is cloned read-only at
`C:\Users\jason\AppData\Local\Temp\claude\c--Users-jason-Documents-dev-laravel-journal\02d86a64-b8a9-4403-b49c-4fbeee373c70\scratchpad\accounting`
(if missing, `git clone https://github.com/consilience/accounting <scratchpad>/accounting`). All code needed is embedded in this plan; the clone is only for cross-checking.

## Global Constraints

- Package name `academe/laravel-journal`; namespace `Academe\LaravelJournal` (PSR-4 from `src/`); tests namespace `Academe\LaravelJournal\Tests` from `tests/`.
- Minimum PHP `^8.2`; Laravel `illuminate/support` + `illuminate/database` `^12.0`; `moneyphp/money ^4.0`.
- Tables: `journal_ledgers`, `journals`, `journal_transactions`. Config file: `config/journal.php`.
- All money stored as integer minor units. Never floats.
- `declare(strict_types=1);` at the top of every PHP file in `src/`.
- Behaviour of the fork is preserved except the cleanups listed in the spec §2.
- Working directory: `c:\Users\jason\Documents\dev\laravel-journal`. Windows shell: run Pest as `vendor\bin\pest` (PowerShell) — in Git Bash use `vendor/bin/pest`.
- Commit after every green test cycle. Commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Package skeleton and test harness

**Files:**
- Create: `composer.json`
- Create: `.gitignore`
- Create: `pint.json`
- Create: `phpstan.neon`
- Create: `phpunit.xml`
- Create: `config/journal.php`
- Create: `src/JournalServiceProvider.php`
- Create: `tests/TestCase.php`
- Create: `tests/Pest.php`
- Test: `tests/Unit/ServiceProviderTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `Academe\LaravelJournal\JournalServiceProvider`; config keys `journal.base_currency` (string, default `'GBP'`), `journal.models.ledger`, `journal.models.journal`, `journal.models.transaction` (class-strings); `Academe\LaravelJournal\Tests\TestCase` (Testbench base with package provider + migrations loaded); Pest configured so `tests/Feature` uses `RefreshDatabase`.

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "academe/laravel-journal",
    "description": "Accounting journals and double-entry bookkeeping for Eloquent models",
    "license": "MIT",
    "keywords": ["laravel", "accounting", "journal", "ledger", "double-entry", "bookkeeping"],
    "authors": [
        {
            "name": "Jason Judge",
            "email": "jason.judge@academe.co.uk"
        }
    ],
    "require": {
        "php": "^8.2",
        "illuminate/database": "^12.0",
        "illuminate/support": "^12.0",
        "moneyphp/money": "^4.0"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0",
        "pestphp/pest": "^3.0",
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.13"
    },
    "autoload": {
        "psr-4": {
            "Academe\\LaravelJournal\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Academe\\LaravelJournal\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Academe\\LaravelJournal\\JournalServiceProvider"
            ]
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "scripts": {
        "test": "pest",
        "lint": "pint --test",
        "fix": "pint",
        "analyse": "phpstan analyse"
    }
}
```

- [ ] **Step 2: Create .gitignore**

```gitignore
/vendor/
composer.lock
/.phpunit.cache/
.phpunit.result.cache
/build/
.idea/
.vscode/
```

(`composer.lock` is ignored because this is a library, not an application.)

- [ ] **Step 3: Create pint.json**

```json
{
    "preset": "laravel"
}
```

- [ ] **Step 4: Create phpstan.neon**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6
    paths:
        - src
```

- [ ] **Step 5: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 6: Create config/journal.php**

The model class-strings are plain strings here (not `::class` constants) so the config file has no dependency on classes that are created in later tasks.

```php
<?php

return [
    /*
     * ISO 4217 currency code used when a journal is initialised
     * without an explicit currency.
     */
    'base_currency' => 'GBP',

    /*
     * Override these to substitute your own model classes.
     * Custom classes should extend the package models.
     */
    'models' => [
        'ledger' => \Academe\LaravelJournal\Models\Ledger::class,
        'journal' => \Academe\LaravelJournal\Models\Journal::class,
        'transaction' => \Academe\LaravelJournal\Models\JournalTransaction::class,
    ],
];
```

Note: `::class` on a not-yet-existing class is fine — PHP resolves it lexically without autoloading.

- [ ] **Step 7: Create src/JournalServiceProvider.php**

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Illuminate\Support\ServiceProvider;

class JournalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/journal.php', 'journal');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/journal.php' => config_path('journal.php'),
        ], 'journal-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'journal-migrations');
    }
}
```

- [ ] **Step 8: Create the migrations directory placeholder**

`publishesMigrations()` needs the directory to exist. Create `database/migrations/.gitkeep` (empty file). Task 4 fills it.

- [ ] **Step 9: Create tests/TestCase.php**

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests;

use Academe\LaravelJournal\JournalServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            JournalServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if (is_dir(__DIR__ . '/Fixtures/migrations')) {
            $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
        }
    }
}
```

(Testbench 10 ships a `testing` connection preconfigured as sqlite `:memory:`. The `is_dir` guard lets this task pass before Task 4 creates the fixtures.)

- [ ] **Step 10: Create tests/Pest.php**

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class)->in('Unit');
uses(TestCase::class, RefreshDatabase::class)->in('Feature');
```

- [ ] **Step 11: Write the failing smoke test**

Create `tests/Unit/ServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

it('merges the package config', function () {
    expect(config('journal.base_currency'))->toBe('GBP');
    expect(config('journal.models.ledger'))->toBe('Academe\LaravelJournal\Models\Ledger');
    expect(config('journal.models.journal'))->toBe('Academe\LaravelJournal\Models\Journal');
    expect(config('journal.models.transaction'))->toBe('Academe\LaravelJournal\Models\JournalTransaction');
});
```

- [ ] **Step 12: Install dependencies**

Run: `composer install`
Expected: installs with no errors. If `pestphp/pest-plugin` prompts about plugins, the `allow-plugins` config already permits it.

Run: `composer validate --strict`
Expected: `./composer.json is valid`

- [ ] **Step 13: Run the test suite**

Run: `vendor\bin\pest`
Expected: PASS — 1 test (`merges the package config`), 4 assertions.

- [ ] **Step 14: Commit**

```bash
git add composer.json .gitignore pint.json phpstan.neon phpunit.xml config src database tests
git commit -m "feat: package skeleton, service provider, config, and test harness

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Exceptions

**Files:**
- Create: `src/Exceptions/JournalException.php`
- Create: `src/Exceptions/JournalAlreadyExists.php`
- Create: `src/Exceptions/InvalidJournalEntryValue.php`
- Create: `src/Exceptions/InvalidJournalMethod.php`
- Create: `src/Exceptions/DebitsAndCreditsDoNotEqual.php`
- Create: `src/Exceptions/TransactionCouldNotBeProcessed.php`
- Create: `src/Exceptions/CurrencyMismatch.php`
- Test: `tests/Unit/ExceptionsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: all seven exception classes in `Academe\LaravelJournal\Exceptions`. Every concrete exception extends `JournalException` and is constructible with zero arguments (sensible default message). `DebitsAndCreditsDoNotEqual::__construct(?string $detail = null)`. `TransactionCouldNotBeProcessed::__construct(?string $message = null, ?Throwable $previous = null)`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ExceptionsTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\Exceptions\JournalException;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;

it('extends JournalException with a default message', function (string $class, string $messageFragment) {
    $exception = new $class();

    expect($exception)->toBeInstanceOf(JournalException::class);
    expect($exception->getMessage())->toContain($messageFragment);
})->with([
    [JournalAlreadyExists::class, 'already exists'],
    [InvalidJournalEntryValue::class, 'positive value'],
    [InvalidJournalMethod::class, 'credit or debit'],
    [DebitsAndCreditsDoNotEqual::class, 'debits equal credits'],
    [TransactionCouldNotBeProcessed::class, 'could not be processed'],
    [CurrencyMismatch::class, 'currency'],
]);

it('appends detail to the unbalanced-group message', function () {
    $exception = new DebitsAndCreditsDoNotEqual('credits == 100 and debits == 99');

    expect($exception->getMessage())
        ->toContain('debits equal credits')
        ->toContain('credits == 100 and debits == 99');
});

it('chains the underlying exception on commit failure', function () {
    $original = new RuntimeException('db went away');
    $exception = new TransactionCouldNotBeProcessed(previous: $original);

    expect($exception->getPrevious())->toBe($original);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Unit/ExceptionsTest.php`
Expected: FAIL — `Class "Academe\LaravelJournal\Exceptions\..." not found`.

- [ ] **Step 3: Implement the exceptions**

`src/Exceptions/JournalException.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use Exception;

class JournalException extends Exception
{
}
```

`src/Exceptions/JournalAlreadyExists.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class JournalAlreadyExists extends JournalException
{
    public function __construct(string $message = 'Journal already exists for this model.')
    {
        parent::__construct($message);
    }
}
```

`src/Exceptions/InvalidJournalEntryValue.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class InvalidJournalEntryValue extends JournalException
{
    public function __construct(string $message = 'Journal transaction entries must be a positive value.')
    {
        parent::__construct($message);
    }
}
```

`src/Exceptions/InvalidJournalMethod.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class InvalidJournalMethod extends JournalException
{
    public function __construct(string $message = 'Journal methods must be credit or debit.')
    {
        parent::__construct($message);
    }
}
```

`src/Exceptions/DebitsAndCreditsDoNotEqual.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class DebitsAndCreditsDoNotEqual extends JournalException
{
    public function __construct(?string $detail = null)
    {
        $message = 'Double entry requires that debits equal credits.';

        if ($detail !== null) {
            $message .= ' ' . $detail;
        }

        parent::__construct($message);
    }
}
```

`src/Exceptions/TransactionCouldNotBeProcessed.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use Throwable;

class TransactionCouldNotBeProcessed extends JournalException
{
    public function __construct(
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? 'Double-entry transaction group could not be processed.',
            0,
            $previous,
        );
    }
}
```

`src/Exceptions/CurrencyMismatch.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

class CurrencyMismatch extends JournalException
{
    public function __construct(string $message = 'Amount currency does not match the journal currency.')
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Unit/ExceptionsTest.php`
Expected: PASS — 8 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Exceptions tests/Unit/ExceptionsTest.php
git commit -m "feat: exception hierarchy rooted at JournalException

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Casts and LedgerType enum

**Files:**
- Create: `src/Casts/CurrencyCast.php`
- Create: `src/Casts/MoneyCast.php`
- Create: `src/Enums/LedgerType.php`
- Test: `tests/Unit/CastsTest.php`
- Test: `tests/Unit/LedgerTypeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `CurrencyCast` (cast argument: currency column name, e.g. `CurrencyCast::class . ':currency_code'`) mapping column string ⇄ `Money\Currency`. `MoneyCast` (cast arguments: currency column, amount column, e.g. `MoneyCast::class . ':currency_code,balance'`) mapping integer minor units + currency code ⇄ `Money\Money`; both directions null-safe. `LedgerType` string-backed enum with cases `ASSET='asset'`, `EXPENSE='expense'`, `LIABILITY='liability'`, `EQUITY='equity'`, `INCOME='income'`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/CastsTest.php`. The casts are exercised directly against a throwaway model instance — no database needed.

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
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
    $cast = new MoneyCast();

    $money = $cast->get($model(), 'price', null, [
        'price_currency' => 'USD',
        'price_amount' => 42,
    ]);

    expect($money)->toEqual(new Money(42, new Currency('USD')));
});
```

Create `tests/Unit/LedgerTypeTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Enums\LedgerType;

it('defines the five ledger types', function () {
    expect(array_map(fn (LedgerType $t) => $t->value, LedgerType::cases()))
        ->toBe(['asset', 'expense', 'liability', 'equity', 'income']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Unit/CastsTest.php tests/Unit/LedgerTypeTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the casts and enum**

`src/Casts/CurrencyCast.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;

/**
 * Casts an ISO 4217 currency code column to a Money\Currency object.
 * The column name defaults to the cast key and can be overridden
 * with a cast argument: CurrencyCast::class . ':currency_code'.
 */
class CurrencyCast implements CastsAttributes
{
    public function __construct(
        protected ?string $columnName = null,
    ) {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Currency
    {
        $code = $value ?: ($attributes[$this->columnName ?? $key] ?? null);

        return $code ? new Currency($code) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$this->columnName ?? $key => $value?->getCode()];
    }
}
```

`src/Casts/MoneyCast.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;
use Money\Money;

/**
 * Casts an integer minor-units column plus a currency code column
 * to a Money\Money object.
 *
 * The default column names are {key}_currency and {key}_amount;
 * both can be overridden with cast arguments:
 * MoneyCast::class . ':currency_code,balance'.
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        protected ?string $currencyColumn = null,
        protected ?string $amountColumn = null,
    ) {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $currencyCode = $attributes[$this->currencyColumn ?? $key . '_currency'] ?? null;
        $minorUnits = $attributes[$this->amountColumn ?? $key . '_amount'] ?? null;

        if ($currencyCode === null || $minorUnits === null) {
            return null;
        }

        return new Money($minorUnits, new Currency($currencyCode));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->currencyColumn ?? $key . '_currency' => null,
                $this->amountColumn ?? $key . '_amount' => null,
            ];
        }

        return [
            $this->currencyColumn ?? $key . '_currency' => $value->getCurrency()->getCode(),
            $this->amountColumn ?? $key . '_amount' => $value->getAmount(),
        ];
    }
}
```

`src/Enums/LedgerType.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Enums;

/**
 * General ledger account types.
 */
enum LedgerType: string
{
    // Debit accounts: balance reported as debit - credit.
    case ASSET = 'asset';
    case EXPENSE = 'expense';

    // Credit accounts: balance reported as credit - debit.
    case LIABILITY = 'liability';
    case EQUITY = 'equity'; // aka capital
    case INCOME = 'income'; // aka revenue
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Unit`
Expected: PASS — all Unit tests including the 10 new ones.

- [ ] **Step 5: Commit**

```bash
git add src/Casts src/Enums tests/Unit/CastsTest.php tests/Unit/LedgerTypeTest.php
git commit -m "feat: Money/Currency casts and LedgerType enum

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Package migrations and test fixtures

**Files:**
- Create: `database/migrations/2026_07_11_000000_create_journal_ledgers_table.php`
- Create: `database/migrations/2026_07_11_010000_create_journals_table.php`
- Create: `database/migrations/2026_07_11_020000_create_journal_transactions_table.php`
- Delete: `database/migrations/.gitkeep`
- Create: `tests/Fixtures/Models/User.php`
- Create: `tests/Fixtures/Models/Account.php`
- Create: `tests/Fixtures/Models/Product.php`
- Create: `tests/Fixtures/Models/CompanyJournal.php`
- Create: `tests/Fixtures/migrations/2026_07_11_100000_create_fixture_tables.php`
- Test: `tests/Feature/MigrationsTest.php`

**Interfaces:**
- Consumes: `TestCase::defineDatabaseMigrations()` from Task 1 (already loads both migration directories).
- Produces: tables `journal_ledgers` (id, timestamps, name, type), `journals` (id, timestamps, ledger_id nullable FK, balance bigint default 0, currency_code char(3), owner_type/owner_id morph + index), `journal_transactions` (uuid id PK, uuid transaction_group nullable indexed, timestamps, journal_id FK indexed, debit/credit nullable bigints, currency_code, memo, tags json, reference nullable morph + index, post_date datetime indexed, softDeletes). Fixture tables `users`, `accounts`, `products`, `company_journals`. Fixture model classes in `Academe\LaravelJournal\Tests\Fixtures\Models` (traits added in Task 7 — plain models for now, all with `protected $guarded = [];`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MigrationsTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\pest tests/Feature/MigrationsTest.php`
Expected: FAIL — `Schema::hasTable('journal_ledgers')` is false.

- [ ] **Step 3: Create the package migrations**

`database/migrations/2026_07_11_000000_create_journal_ledgers_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_ledgers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('name');

            // One of the LedgerType enum values:
            // 'asset', 'liability', 'equity', 'income', 'expense'.
            $table->string('type', 30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_ledgers');
    }
};
```

`database/migrations/2026_07_11_010000_create_journals_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('ledger_id')
                ->nullable()
                ->constrained('journal_ledgers');

            // Cached balance in minor units; recomputed on every
            // transaction save/delete.
            $table->bigInteger('balance')->default(0);

            // ISO 4217.
            $table->string('currency_code', 3);

            // The model instance this journal belongs to.
            $table->morphs('owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
```

`database/migrations/2026_07_11_020000_create_journal_transactions_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Shared UUID linking the entries of one double-entry group.
            $table->uuid('transaction_group')->nullable()->index();

            $table->timestamps();

            $table->foreignId('journal_id')
                ->index()
                ->constrained('journals');

            // Minor units; exactly one of debit/credit is set per row.
            $table->bigInteger('debit')->nullable();
            $table->bigInteger('credit')->nullable();

            // ISO 4217; always matches the journal currency.
            $table->string('currency_code', 3);

            $table->text('memo')->nullable();
            $table->json('tags')->nullable();

            // Optional link to any model this entry references.
            $table->nullableMorphs('reference');

            $table->dateTime('post_date')->index();

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_transactions');
    }
};
```

Delete `database/migrations/.gitkeep`.

- [ ] **Step 4: Create the fixture models**

`tests/Fixtures/Models/User.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $guarded = [];
}
```

`tests/Fixtures/Models/Account.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];
}
```

`tests/Fixtures/Models/Product.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];
}
```

`tests/Fixtures/Models/CompanyJournal.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal host model used to hang company-level journals on
 * (cash, accounts receivable, income, ...) in tests.
 */
class CompanyJournal extends Model
{
    protected $guarded = [];
}
```

- [ ] **Step 5: Create the fixture migration**

`tests/Fixtures/migrations/2026_07_11_100000_create_fixture_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            $table->string('email');
            $table->string('password');
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            $table->integer('price'); // minor units
        });

        Schema::create('company_journals', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_journals');
        Schema::dropIfExists('products');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('users');
    }
};
```

(The old test schema stored `price` as a float; fixtures now use integer minor units in line with the no-floats constraint.)

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor\bin\pest tests/Feature/MigrationsTest.php`
Expected: PASS — 4 tests.

- [ ] **Step 7: Commit**

```bash
git add database tests/Fixtures tests/Feature/MigrationsTest.php
git commit -m "feat: package migrations and test fixtures

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Journal and JournalTransaction model structure

**Files:**
- Create: `src/Models/Journal.php`
- Create: `src/Models/JournalTransaction.php`
- Test: `tests/Feature/JournalModelTest.php`

**Interfaces:**
- Consumes: casts (Task 3), migrations (Task 4), config keys (Task 1).
- Produces: `Journal` model — `owner(): MorphTo`, `ledger(): BelongsTo`, `transactions(): HasMany`, `assignToLedger(Ledger $ledger): self`, properties `Money|null $balance`, `Currency|null $currency`, `string $currency_code`; `protected $guarded = ['id']`. `JournalTransaction` model — `HasUuids` string PK, `journal(): BelongsTo`, `reference(): MorphTo`, `Money|null $credit`, `Money|null $debit`, `Money $amount` accessor (credit positive, debit negative, zero if neither), `Currency $currency`, `Carbon $post_date`, `array|null $tags`. Balance recomputation hooks and posting methods arrive in Task 6; `Journal::resetCurrentBalance()` is defined in Task 6. `assignToLedger` type-hints `Ledger`, created in Task 8 — to keep this task self-contained and green, reference the class name only (no instantiation happens until Task 8's tests).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/JournalModelTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Tests\Fixtures\Models\User;
use Money\Currency;
use Money\Money;

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

it('creates a journal with a zero balance by default', function () {
    $journal = makeUserJournal();

    expect($journal->fresh()->balance)->toEqual(new Money(0, new Currency('USD')));
});

it('casts the journal currency', function () {
    $journal = makeUserJournal('GBP');

    expect($journal->currency)->toEqual(new Currency('GBP'));
});

it('resolves the journal owner morph', function () {
    $journal = makeUserJournal();

    expect($journal->owner)->toBeInstanceOf(User::class);
});

it('generates uuid transaction keys', function () {
    $journal = makeUserJournal();

    $transaction = new JournalTransaction([
        'currency_code' => 'USD',
        'credit' => new Money(100, new Currency('USD')),
        'post_date' => now(),
    ]);
    $journal->transactions()->save($transaction);

    expect($transaction->id)->toBeString()->toHaveLength(36);
    expect($transaction->journal->is($journal))->toBeTrue();
});

it('exposes credit and debit as Money and amount as signed Money', function () {
    $journal = makeUserJournal();

    $credit = new JournalTransaction([
        'currency_code' => 'USD',
        'credit' => new Money(150, new Currency('USD')),
        'post_date' => now(),
    ]);
    $journal->transactions()->save($credit);

    $debit = new JournalTransaction([
        'currency_code' => 'USD',
        'debit' => new Money(60, new Currency('USD')),
        'post_date' => now(),
    ]);
    $journal->transactions()->save($debit);

    expect($credit->fresh()->credit)->toEqual(new Money(150, new Currency('USD')));
    expect($credit->fresh()->amount)->toEqual(new Money(150, new Currency('USD')));
    expect($debit->fresh()->debit)->toEqual(new Money(60, new Currency('USD')));
    expect($debit->fresh()->amount)->toEqual(new Money(-60, new Currency('USD')));
});
```

Note: `JournalTransaction` gets a `saved` hook in Task 6 that calls `$this->journal->resetCurrentBalance()`. In this task that method does not exist yet, so the model here ships **without** the hook — the test would otherwise fail on a missing method.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/JournalModelTest.php`
Expected: FAIL — `Class "Academe\LaravelJournal\Models\Journal" not found`.

- [ ] **Step 3: Implement the models (structure only)**

`src/Models/Journal.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Money\Currency;
use Money\Money;

/**
 * A journal records the transactions of a single owner model instance.
 *
 * @property Money|null $balance
 * @property string $currency_code ISO 4217
 * @property Currency|null $currency
 * @property CarbonInterface $updated_at
 * @property CarbonInterface $created_at
 * @property Model $owner
 * @property Ledger|null $ledger
 */
class Journal extends Model
{
    protected $table = 'journals';

    protected $guarded = ['id'];

    protected $attributes = [
        'balance' => 0,
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCast::class . ':currency_code',
            'balance' => MoneyCast::class . ':currency_code,balance',
        ];
    }

    /**
     * The model instance this journal belongs to.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(config('journal.models.ledger'));
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(config('journal.models.transaction'));
    }

    public function assignToLedger(Ledger $ledger): self
    {
        $ledger->journals()->save($this);

        return $this;
    }
}
```

`src/Models/JournalTransaction.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Casts\CurrencyCast;
use Academe\LaravelJournal\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Money\Currency;
use Money\Money;

/**
 * A single journal entry: either a credit or a debit, in minor units.
 *
 * @property string $id
 * @property int $journal_id
 * @property string|null $transaction_group
 * @property Money|null $credit
 * @property Money|null $debit
 * @property Money $amount credit as positive, debit as negative
 * @property Currency $currency
 * @property string $currency_code ISO 4217
 * @property string|null $memo
 * @property array|null $tags
 * @property Journal $journal
 * @property Carbon $post_date
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 */
class JournalTransaction extends Model
{
    use HasUuids;

    protected $table = 'journal_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'post_date' => 'datetime',
            'tags' => 'array',
            'currency' => CurrencyCast::class . ':currency_code',
            'credit' => MoneyCast::class . ':currency_code,credit',
            'debit' => MoneyCast::class . ':currency_code,debit',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(config('journal.models.journal'));
    }

    /**
     * Any model this entry references.
     *
     * To associate: $transaction->reference()->associate($model)->save();
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The signed amount: credit as positive, debit as negative.
     */
    public function getAmountAttribute(): Money
    {
        if (($this->attributes['credit'] ?? null) !== null) {
            return $this->credit;
        }

        if (($this->attributes['debit'] ?? null) !== null) {
            return $this->debit->multiply(-1);
        }

        return new Money(0, $this->currency);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/JournalModelTest.php`
Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Models tests/Feature/JournalModelTest.php
git commit -m "feat: Journal and JournalTransaction model structure

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Posting and balances

**Files:**
- Modify: `src/Models/Journal.php` (add posting + balance methods)
- Modify: `src/Models/JournalTransaction.php` (add saved/deleted hooks)
- Test: `tests/Feature/PostingTest.php`

**Interfaces:**
- Consumes: models from Task 5, `CurrencyMismatch` from Task 2, `makeUserJournal()` helper — move it out of `JournalModelTest.php` into `tests/Pest.php` in this task so both files share it (Pest loads `Pest.php` for all tests).
- Produces on `Journal`:
  - `credit(Money|int $value, ?string $memo = null, ?CarbonInterface $postDate = null, ?string $transactionGroup = null): JournalTransaction`
  - `debit(...)` same signature
  - `currentBalance(): Money` (to now), `balanceOn(CarbonInterface $date): Money` (end of day), `totalBalance(): Money` (all rows incl. future), `debitBalanceOn(CarbonInterface $date): Money`, `creditBalanceOn(CarbonInterface $date): Money`, `resetCurrentBalance(): Money` (recompute + save cached balance).
- Produces on `JournalTransaction`: `saved`/`deleted` model events call `$this->journal->resetCurrentBalance()`.

- [ ] **Step 1: Move the helper**

Cut `makeUserJournal()` from `tests/Feature/JournalModelTest.php` and add it to `tests/Pest.php` (after the `uses()` lines), unchanged:

```php
use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Tests\Fixtures\Models\User;

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
```

Run: `vendor\bin\pest tests/Feature/JournalModelTest.php` — Expected: still PASS.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/PostingTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\CurrencyMismatch;
use Academe\LaravelJournal\Models\JournalTransaction;
use Money\Currency;
use Money\Money;

it('credits and debits a journal and tracks the current balance', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(10000));

    expect($journal->currentBalance())->toEqual(Money::USD(10000));
    expect($journal->currentBalance()->getAmount())->toBe('10000');

    $journal->debit(Money::USD(10099));

    expect($journal->currentBalance())->toEqual(Money::USD(-99));
});

it('accepts integer minor units in the journal currency', function () {
    $journal = makeUserJournal('GBP');

    $transaction = $journal->credit(2500, 'top-up');

    expect($transaction)->toBeInstanceOf(JournalTransaction::class);
    expect($transaction->credit)->toEqual(new Money(2500, new Currency('GBP')));
    expect($transaction->memo)->toBe('top-up');
    expect($journal->currentBalance())->toEqual(new Money(2500, new Currency('GBP')));
});

it('records absolute values regardless of sign', function () {
    $journal = makeUserJournal();

    $journal->debit(-500);

    expect($journal->currentBalance())->toEqual(Money::USD(-500));
});

it('rejects a Money value in a different currency', function () {
    $journal = makeUserJournal('GBP');

    $journal->credit(Money::USD(100));
})->throws(CurrencyMismatch::class);

it('caches the balance on the journal row after each post', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(750));

    expect($journal->fresh()->balance)->toEqual(Money::USD(750));

    $journal->debit(Money::USD(250));

    expect($journal->fresh()->balance)->toEqual(Money::USD(500));
});

it('recomputes the cached balance when a transaction is deleted', function () {
    $journal = makeUserJournal();

    $keep = $journal->credit(Money::USD(1000));
    $remove = $journal->credit(Money::USD(400));

    $remove->delete();

    expect($journal->fresh()->balance)->toEqual(Money::USD(1000));
});

it('computes balances as of a date and separates future transactions', function () {
    $journal = makeUserJournal();

    $journal->credit(Money::USD(100), 'yesterday', now()->subDay());
    $journal->credit(Money::USD(200), 'today');
    $journal->credit(Money::USD(400), 'future', now()->addDays(7));
    $journal->debit(Money::USD(50), 'yesterday debit', now()->subDay());

    expect($journal->balanceOn(now()->subDay()))->toEqual(Money::USD(50));
    expect($journal->currentBalance())->toEqual(Money::USD(250));
    expect($journal->totalBalance())->toEqual(Money::USD(650));
    expect($journal->debitBalanceOn(now()))->toEqual(Money::USD(50));
    expect($journal->creditBalanceOn(now()))->toEqual(Money::USD(300));
});

it('stamps posts with the transaction group when given', function () {
    $journal = makeUserJournal();

    $transaction = $journal->credit(Money::USD(100), null, null, 'abc-123');

    expect($transaction->transaction_group)->toBe('abc-123');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/PostingTest.php`
Expected: FAIL — `Call to undefined method ...Journal::credit()`.

- [ ] **Step 4: Implement posting and balances on Journal**

Add to `src/Models/Journal.php` (inside the class, after `transactions()`); also add `use Academe\LaravelJournal\Exceptions\CurrencyMismatch;` and `use Carbon\Carbon;` to the imports:

```php
    /**
     * Recompute and save the cached balance column.
     *
     * Uses the total balance, which includes future-dated transactions.
     */
    public function resetCurrentBalance(): Money
    {
        $this->balance = $this->totalBalance();
        $this->save();

        return $this->balance;
    }

    /**
     * The debit-only balance at the end of the given day.
     */
    public function debitBalanceOn(CarbonInterface $date): Money
    {
        $minorUnits = (int) $this->transactions()
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->where('currency_code', $this->currency_code)
            ->sum('debit');

        return new Money($minorUnits, $this->currency);
    }

    /**
     * The credit-only balance at the end of the given day.
     */
    public function creditBalanceOn(CarbonInterface $date): Money
    {
        $minorUnits = (int) $this->transactions()
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->where('currency_code', $this->currency_code)
            ->sum('credit');

        return new Money($minorUnits, $this->currency);
    }

    /**
     * The balance (credit - debit) at the end of the given day.
     */
    public function balanceOn(CarbonInterface $date): Money
    {
        return $this->creditBalanceOn($date)->subtract($this->debitBalanceOn($date));
    }

    /**
     * The balance today, excluding future-dated transactions.
     */
    public function currentBalance(): Money
    {
        return $this->balanceOn(Carbon::now());
    }

    /**
     * The balance across all transactions, including future-dated ones.
     */
    public function totalBalance(): Money
    {
        $credit = (int) $this->transactions()
            ->where('currency_code', $this->currency_code)
            ->sum('credit');

        $debit = (int) $this->transactions()
            ->where('currency_code', $this->currency_code)
            ->sum('debit');

        return new Money($credit - $debit, $this->currency);
    }

    /**
     * Post a credit entry. An integer value means minor units in the
     * journal currency; a Money value must match the journal currency.
     */
    public function credit(
        Money|int $value,
        ?string $memo = null,
        ?CarbonInterface $postDate = null,
        ?string $transactionGroup = null,
    ): JournalTransaction {
        return $this->post(
            credit: $this->normalizeAmount($value),
            debit: null,
            memo: $memo,
            postDate: $postDate,
            transactionGroup: $transactionGroup,
        );
    }

    /**
     * Post a debit entry. An integer value means minor units in the
     * journal currency; a Money value must match the journal currency.
     */
    public function debit(
        Money|int $value,
        ?string $memo = null,
        ?CarbonInterface $postDate = null,
        ?string $transactionGroup = null,
    ): JournalTransaction {
        return $this->post(
            credit: null,
            debit: $this->normalizeAmount($value),
            memo: $memo,
            postDate: $postDate,
            transactionGroup: $transactionGroup,
        );
    }

    /**
     * Convert an input amount to absolute Money in the journal currency.
     *
     * @throws CurrencyMismatch
     */
    protected function normalizeAmount(Money|int $value): Money
    {
        if ($value instanceof Money) {
            if (! $value->getCurrency()->equals($this->currency)) {
                throw new CurrencyMismatch(sprintf(
                    'Amount currency %s does not match journal currency %s.',
                    $value->getCurrency()->getCode(),
                    $this->currency_code,
                ));
            }

            return $value->absolute();
        }

        return new Money(abs($value), $this->currency);
    }

    /**
     * Create and save the journal entry.
     */
    protected function post(
        ?Money $credit,
        ?Money $debit,
        ?string $memo,
        ?CarbonInterface $postDate,
        ?string $transactionGroup,
    ): JournalTransaction {
        $transactionClass = config('journal.models.transaction');

        /** @var JournalTransaction $transaction */
        $transaction = new $transactionClass();

        $transaction->credit = $credit;
        $transaction->debit = $debit;
        $transaction->currency = $this->currency;
        $transaction->memo = $memo;
        $transaction->post_date = $postDate ?? Carbon::now();
        $transaction->transaction_group = $transactionGroup;

        $this->transactions()->save($transaction);

        return $transaction;
    }
```

- [ ] **Step 5: Add the balance hooks to JournalTransaction**

Add to `src/Models/JournalTransaction.php` (inside the class, before `journal()`):

```php
    protected static function booted(): void
    {
        // Keep the cached journal balance in sync.
        static::saved(function (self $transaction) {
            $transaction->journal->resetCurrentBalance();
        });

        static::deleted(function (self $transaction) {
            $transaction->journal->resetCurrentBalance();
        });
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature`
Expected: PASS — all Feature tests (Migrations, JournalModel, Posting).

- [ ] **Step 7: Commit**

```bash
git add src/Models tests/Feature/PostingTest.php tests/Feature/JournalModelTest.php tests/Pest.php
git commit -m "feat: posting, currency validation, and balance calculations

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: HasJournal and HasJournalTransactions concerns

**Files:**
- Create: `src/Concerns/HasJournal.php`
- Create: `src/Concerns/HasJournalTransactions.php`
- Modify: `tests/Fixtures/Models/User.php`, `Account.php`, `CompanyJournal.php` (add `HasJournal`)
- Modify: `tests/Fixtures/Models/Product.php` (add `HasJournalTransactions`)
- Test: `tests/Feature/HasJournalTest.php`

**Interfaces:**
- Consumes: `Journal` model (Task 5/6), `JournalAlreadyExists` (Task 2), config (Task 1).
- Produces: `Academe\LaravelJournal\Concerns\HasJournal` — `journal(): MorphOne` (morph name `owner`), `initJournal(Currency|string|null $currency = null, ?int $ledgerId = null): Journal` (throws `JournalAlreadyExists` if called twice; defaults currency to `config('journal.base_currency')`). `Academe\LaravelJournal\Concerns\HasJournalTransactions` — `journalTransactions(): MorphMany` (morph name `reference`). Fixture models now use the traits (Task 8/9 tests rely on this).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/HasJournalTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\Models\Journal;
use Academe\LaravelJournal\Tests\Fixtures\Models\Product;
use Academe\LaravelJournal\Tests\Fixtures\Models\User;
use Money\Currency;
use Money\Money;

function fixtureUser(string $email = 'user@example.com'): User
{
    return User::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => 'secret',
    ]);
}

it('initialises a journal with an explicit currency', function () {
    $user = fixtureUser();

    $journal = $user->initJournal('USD');

    expect($journal)->toBeInstanceOf(Journal::class);
    expect($user->fresh()->journal->is($journal))->toBeTrue();
    expect($journal->currency)->toEqual(new Currency('USD'));
    expect($journal->balance)->toEqual(Money::USD(0));
});

it('initialises a journal with the configured base currency', function () {
    $journal = fixtureUser()->initJournal();

    expect($journal->currency)->toEqual(new Currency('GBP'));
});

it('accepts a Currency object', function () {
    $journal = fixtureUser()->initJournal(new Currency('EUR'));

    expect($journal->currency_code)->toBe('EUR');
});

it('refuses to initialise a second journal', function () {
    $user = fixtureUser();
    $user->initJournal('USD');
    $user->fresh()->initJournal('USD');
})->throws(JournalAlreadyExists::class);

it('exposes transactions referencing a model', function () {
    $user = fixtureUser();
    $journal = $user->initJournal('USD');
    $product = Product::create(['name' => 'Widget', 'price' => 999]);

    $transaction = $journal->credit(Money::USD(999));
    $transaction->reference()->associate($product)->save();

    expect($product->journalTransactions)->toHaveCount(1);
    expect($product->journalTransactions->first()->is($transaction))->toBeTrue();
    expect($transaction->fresh()->reference->is($product))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/HasJournalTest.php`
Expected: FAIL — `Call to undefined method ...User::initJournal()`.

- [ ] **Step 3: Implement the concerns**

`src/Concerns/HasJournal.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Concerns;

use Academe\LaravelJournal\Exceptions\JournalAlreadyExists;
use Academe\LaravelJournal\Models\Journal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Money\Currency;
use Money\Money;

/**
 * For models that own an accounting journal.
 *
 * @mixin Model
 *
 * @property Journal|null $journal
 */
trait HasJournal
{
    public function journal(): MorphOne
    {
        return $this->morphOne(config('journal.models.journal'), 'owner');
    }

    /**
     * Initialise a journal for this model instance.
     *
     * @throws JournalAlreadyExists
     */
    public function initJournal(
        Currency|string|null $currency = null,
        ?int $ledgerId = null,
    ): Journal {
        if ($this->journal) {
            throw new JournalAlreadyExists();
        }

        $currency ??= config('journal.base_currency');

        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        $journalClass = config('journal.models.journal');

        /** @var Journal $journal */
        $journal = new $journalClass();

        $journal->ledger_id = $ledgerId;
        $journal->balance = new Money(0, $currency);

        $this->journal()->save($journal);

        return $journal;
    }
}
```

`src/Concerns/HasJournalTransactions.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Concerns;

use Academe\LaravelJournal\Models\JournalTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For models that journal transactions may reference.
 *
 * @mixin Model
 *
 * @property Collection<int, JournalTransaction> $journalTransactions
 */
trait HasJournalTransactions
{
    public function journalTransactions(): MorphMany
    {
        return $this->morphMany(config('journal.models.transaction'), 'reference');
    }
}
```

- [ ] **Step 4: Add the traits to the fixtures**

In `tests/Fixtures/Models/User.php`, `Account.php`, and `CompanyJournal.php`, add:

```php
use Academe\LaravelJournal\Concerns\HasJournal;
```

and inside each class body:

```php
    use HasJournal;
```

In `tests/Fixtures/Models/Product.php`, add:

```php
use Academe\LaravelJournal\Concerns\HasJournalTransactions;
```

and inside the class body:

```php
    use HasJournalTransactions;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature/HasJournalTest.php`
Expected: PASS — 5 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Concerns tests/Fixtures/Models tests/Feature/HasJournalTest.php
git commit -m "feat: HasJournal and HasJournalTransactions concerns

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Ledger model

**Files:**
- Create: `src/Models/Ledger.php`
- Test: `tests/Feature/LedgerTest.php`
- Modify: `tests/Pest.php` (add the `companyBooks()` helper)

**Interfaces:**
- Consumes: `LedgerType` (Task 3), `Journal` (Tasks 5–6), `HasJournal` fixtures (Task 7).
- Produces: `Ledger` model — `protected $guarded = ['id']`, `type` cast to `LedgerType`, `journals(): HasMany`, `journalTransactions(): HasManyThrough`, `currentBalance(Currency|string $currency): Money` computed with SQL sums filtered by currency (debit − credit for asset/expense ledgers, credit − debit otherwise). Helper `companyBooks(string $currencyCode = 'USD'): object` in `tests/Pest.php` returning `->assetsLedger`, `->liabilityLedger`, `->equityLedger`, `->incomeLedger`, `->expenseLedger`, `->arJournal`, `->cashJournal`, `->incomeJournal` (used again by Task 9).

- [ ] **Step 1: Add the shared helper to tests/Pest.php**

Append to `tests/Pest.php` (extend the existing `use` block as needed):

```php
use Academe\LaravelJournal\Models\Ledger;
use Academe\LaravelJournal\Tests\Fixtures\Models\CompanyJournal;

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
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/LedgerTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Enums\LedgerType;
use Academe\LaravelJournal\Models\Ledger;
use Money\Currency;
use Money\Money;

it('casts the ledger type to the enum', function () {
    $ledger = Ledger::create(['name' => 'Assets', 'type' => 'asset']);

    expect($ledger->fresh()->type)->toBe(LedgerType::ASSET);
});

it('relates journals assigned to the ledger', function () {
    $books = companyBooks();

    expect($books->assetsLedger->journals)->toHaveCount(2);
    expect($books->arJournal->fresh()->ledger->is($books->assetsLedger))->toBeTrue();
});

it('computes a debit-positive balance for asset ledgers', function () {
    $books = companyBooks();

    $books->cashJournal->debit(Money::USD(5000));
    $books->arJournal->debit(Money::USD(2500));
    $books->cashJournal->credit(Money::USD(1000));

    expect($books->assetsLedger->currentBalance('USD'))->toEqual(Money::USD(6500));
});

it('computes a credit-positive balance for income ledgers', function () {
    $books = companyBooks();

    $books->incomeJournal->credit(Money::USD(7500));

    expect($books->incomeLedger->currentBalance(new Currency('USD')))->toEqual(Money::USD(7500));
});

it('keeps asset and income ledgers in agreement across user journals', function () {
    $books = companyBooks();

    foreach (range(1, 5) as $i) {
        $user = fixtureUser("user{$i}@example.com");
        $journal = $user->initJournal('USD');
        $journal->assignToLedger($books->incomeLedger);

        $journal->credit(Money::USD(10000));
        $books->arJournal->debit(Money::USD(10000));
    }

    expect($books->arJournal->currentBalance())->toEqual(Money::USD(-50000));
    expect($books->assetsLedger->currentBalance('USD'))
        ->toEqual($books->incomeLedger->currentBalance('USD'))
        ->toEqual(Money::USD(50000));
});

it('ignores other currencies when summing', function () {
    $books = companyBooks();
    $gbpJournal = fixtureUser('gbp@example.com')->initJournal('GBP');
    $gbpJournal->assignToLedger($books->assetsLedger);

    $books->cashJournal->debit(Money::USD(5000));
    $gbpJournal->debit(new Money(9999, new Currency('GBP')));

    expect($books->assetsLedger->currentBalance('USD'))->toEqual(Money::USD(5000));
});
```

Note: `fixtureUser()` is defined in `tests/Feature/HasJournalTest.php` — move it into `tests/Pest.php` alongside `companyBooks()` (functions in test files are global, but keeping shared helpers in `Pest.php` is the convention; remove it from `HasJournalTest.php` when moving).

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/LedgerTest.php`
Expected: FAIL — `Class "Academe\LaravelJournal\Models\Ledger" not found`.

- [ ] **Step 4: Implement the Ledger model**

`src/Models/Ledger.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Models;

use Academe\LaravelJournal\Enums\LedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Money\Currency;
use Money\Money;

/**
 * A ledger groups journals under one of the five account types.
 *
 * @property string $name
 * @property LedgerType $type
 */
class Ledger extends Model
{
    protected $table = 'journal_ledgers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
        ];
    }

    public function journals(): HasMany
    {
        return $this->hasMany(config('journal.models.journal'), 'ledger_id');
    }

    public function journalTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            config('journal.models.transaction'),
            config('journal.models.journal'),
            'ledger_id',
            'journal_id',
        );
    }

    /**
     * Sum all transactions in the given currency across the ledger's
     * journals. Asset and expense ledgers report debit - credit;
     * liability, equity, and income ledgers report credit - debit.
     */
    public function currentBalance(Currency|string $currency): Money
    {
        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        $debit = new Money(
            (int) $this->journalTransactions()
                ->where('currency_code', $currency->getCode())
                ->sum('debit'),
            $currency,
        );

        $credit = new Money(
            (int) $this->journalTransactions()
                ->where('currency_code', $currency->getCode())
                ->sum('credit'),
            $currency,
        );

        return match ($this->type) {
            LedgerType::ASSET, LedgerType::EXPENSE => $debit->subtract($credit),
            default => $credit->subtract($debit),
        };
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor\bin\pest tests/Feature`
Expected: PASS — all Feature tests.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Ledger.php tests/Feature/LedgerTest.php tests/Feature/HasJournalTest.php tests/Pest.php
git commit -m "feat: Ledger model with SQL-summed, currency-filtered balances

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: TransactionGroup double-entry service

**Files:**
- Create: `src/TransactionGroup.php`
- Test: `tests/Feature/TransactionGroupTest.php`

**Interfaces:**
- Consumes: `Journal::credit()/debit()` (Task 6), exceptions (Task 2), `companyBooks()` helper (Task 8).
- Produces: `Academe\LaravelJournal\TransactionGroup` —
  - `static make(): static`
  - `addTransaction(Journal $journal, string $method, Money $money, ?string $memo = null, ?Model $reference = null, ?CarbonInterface $postDate = null): static` (throws `InvalidJournalMethod` for methods other than `credit`/`debit`; `InvalidJournalEntryValue` for zero/negative amounts)
  - `pending(): array`
  - `commit(): string` — asserts credits equal debits (`DebitsAndCreditsDoNotEqual`), posts all entries in one DB transaction stamped with a shared ordered-UUID group id, persists references, returns the group UUID; wraps any failure in `TransactionCouldNotBeProcessed` with `$previous`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TransactionGroupTest.php`:

```php
<?php

declare(strict_types=1);

use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Models\JournalTransaction;
use Academe\LaravelJournal\Tests\Fixtures\Models\Product;
use Academe\LaravelJournal\TransactionGroup;
use Money\Money;

it('rejects methods other than credit or debit', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'banana', Money::USD(100));
})->throws(InvalidJournalMethod::class);

it('rejects zero amounts', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(0));
})->throws(InvalidJournalEntryValue::class);

it('rejects negative amounts', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(-100));
})->throws(InvalidJournalEntryValue::class);

it('rejects unbalanced groups at commit', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(9901))
        ->addTransaction($books->arJournal, 'credit', Money::USD(9900))
        ->commit();
})->throws(DebitsAndCreditsDoNotEqual::class);

it('posts balanced groups so journal values mirror each other', function () {
    $books = companyBooks();

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(10000))
        ->addTransaction($books->arJournal, 'credit', Money::USD(10000))
        ->commit();

    expect($books->cashJournal->currentBalance())
        ->toEqual($books->arJournal->currentBalance()->multiply(-1));
});

it('stamps every entry with the returned group uuid', function () {
    $books = companyBooks();

    $groupUuid = TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(10000))
        ->addTransaction($books->arJournal, 'credit', Money::USD(10000))
        ->addTransaction($books->cashJournal, 'debit', Money::USD(7500))
        ->addTransaction($books->arJournal, 'credit', Money::USD(7500))
        ->commit();

    expect(JournalTransaction::where('transaction_group', $groupUuid)->count())->toBe(4);
});

it('persists memo, post date, and reference on committed entries', function () {
    $books = companyBooks();
    $product = Product::create(['name' => 'Widget', 'price' => 999]);
    $postDate = now()->subDays(3);

    $groupUuid = TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD(999), 'sale', $product, $postDate)
        ->addTransaction($books->incomeJournal, 'credit', Money::USD(999), 'sale', $product, $postDate)
        ->commit();

    $entries = JournalTransaction::where('transaction_group', $groupUuid)->get();

    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        expect($entry->memo)->toBe('sale');
        expect($entry->post_date->toDateString())->toBe($postDate->toDateString());
        expect($entry->reference->is($product))->toBeTrue();
    }
});

it('keeps ledgers balanced after a group commit', function () {
    $books = companyBooks();

    $value = (int) (mt_rand(1000000, 9999999) * 1.987654321);

    TransactionGroup::make()
        ->addTransaction($books->cashJournal, 'debit', Money::USD($value))
        ->addTransaction($books->incomeJournal, 'credit', Money::USD($value))
        ->commit();

    expect($books->assetsLedger->currentBalance('USD'))->toEqual(Money::USD($value));
    expect($books->incomeLedger->currentBalance('USD'))->toEqual(Money::USD($value));
});

it('keeps ledgers balanced after complex activity', function () {
    $books = companyBooks();

    foreach (range(1, 1000) as $i) {
        $a = (int) (mt_rand(1, 99999999) * 2.25);
        $b = (int) (mt_rand(1, 99999999) * 3.50);

        TransactionGroup::make()
            ->addTransaction($books->cashJournal, 'debit', Money::USD($a))
            ->addTransaction($books->arJournal, 'debit', Money::USD($b))
            ->addTransaction($books->incomeJournal, 'credit', Money::USD($a + $b))
            ->commit();
    }

    expect($books->assetsLedger->currentBalance('USD'))
        ->toEqual($books->incomeLedger->currentBalance('USD'));
});
```

(This ports the original 1,000-iteration simulation faithfully; expect it to take several seconds. If the suite becomes painful, reduce the iteration count in a later commit — do not skip the test.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\pest tests/Feature/TransactionGroupTest.php`
Expected: FAIL — `Class "Academe\LaravelJournal\TransactionGroup" not found`.

- [ ] **Step 3: Implement TransactionGroup**

`src/TransactionGroup.php`:

```php
<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Academe\LaravelJournal\Exceptions\DebitsAndCreditsDoNotEqual;
use Academe\LaravelJournal\Exceptions\InvalidJournalEntryValue;
use Academe\LaravelJournal\Exceptions\InvalidJournalMethod;
use Academe\LaravelJournal\Exceptions\TransactionCouldNotBeProcessed;
use Academe\LaravelJournal\Models\Journal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Money\Money;
use Throwable;

/**
 * Builds a balanced double-entry transaction group and commits it
 * atomically. All entries share a group UUID.
 */
class TransactionGroup
{
    /**
     * @var array<int, array{
     *     journal: Journal,
     *     method: string,
     *     money: Money,
     *     memo: string|null,
     *     reference: Model|null,
     *     postDate: CarbonInterface|null,
     * }>
     */
    protected array $pending = [];

    public static function make(): static
    {
        return new static();
    }

    /**
     * Queue a credit or debit against a journal.
     *
     * @throws InvalidJournalMethod
     * @throws InvalidJournalEntryValue
     */
    public function addTransaction(
        Journal $journal,
        string $method,
        Money $money,
        ?string $memo = null,
        ?Model $reference = null,
        ?CarbonInterface $postDate = null,
    ): static {
        if (! in_array($method, ['credit', 'debit'], true)) {
            throw new InvalidJournalMethod();
        }

        if ($money->isZero() || $money->isNegative()) {
            throw new InvalidJournalEntryValue();
        }

        $this->pending[] = [
            'journal' => $journal,
            'method' => $method,
            'money' => $money,
            'memo' => $memo,
            'reference' => $reference,
            'postDate' => $postDate,
        ];

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Commit all pending entries atomically.
     *
     * @return string the shared transaction group UUID
     *
     * @throws DebitsAndCreditsDoNotEqual
     * @throws TransactionCouldNotBeProcessed
     */
    public function commit(): string
    {
        $this->assertCreditsEqualDebits();

        try {
            return DB::transaction(function (): string {
                $groupUuid = (string) Str::orderedUuid();

                foreach ($this->pending as $entry) {
                    $transaction = $entry['journal']->{$entry['method']}(
                        $entry['money'],
                        $entry['memo'],
                        $entry['postDate'],
                        $groupUuid,
                    );

                    if ($entry['reference'] !== null) {
                        $transaction->reference()->associate($entry['reference'])->save();
                    }
                }

                return $groupUuid;
            });
        } catch (Throwable $e) {
            throw new TransactionCouldNotBeProcessed(previous: $e);
        }
    }

    /**
     * @throws DebitsAndCreditsDoNotEqual
     */
    protected function assertCreditsEqualDebits(): void
    {
        $credits = 0;
        $debits = 0;

        foreach ($this->pending as $entry) {
            if ($entry['method'] === 'credit') {
                $credits += (int) $entry['money']->getAmount();
            } else {
                $debits += (int) $entry['money']->getAmount();
            }
        }

        if ($credits !== $debits) {
            throw new DebitsAndCreditsDoNotEqual(
                "In this transaction, credits == {$credits} and debits == {$debits}.",
            );
        }
    }
}
```

Behavioural note (documented fix from the spec): the fork called `reference()->associate($object)` during commit without saving, so group references were never persisted. The `->save()` here fixes that; the `persists memo, post date, and reference` test covers it.

Note: the credits-equal-debits assertion runs **before** the try block, so `DebitsAndCreditsDoNotEqual` surfaces directly; only persistence failures are wrapped in `TransactionCouldNotBeProcessed`. This mirrors the fork.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\pest`
Expected: PASS — full suite (Unit + Feature), 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/TransactionGroup.php tests/Feature/TransactionGroupTest.php
git commit -m "feat: TransactionGroup double-entry service with persisted references

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 10: Static analysis, style, and CI

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: any `src/` file PHPStan or Pint flags

**Interfaces:**
- Consumes: everything prior.
- Produces: green `composer lint`, `composer analyse`, `composer test`; CI workflow running all three on push/PR.

- [ ] **Step 1: Run Pint and fix style**

Run: `vendor\bin\pint`
Expected: fixes applied (or none). Review the diff — Pint must not change behaviour, only formatting.

- [ ] **Step 2: Run PHPStan and fix findings**

Run: `vendor\bin\phpstan analyse`
Expected: errors on first run are likely (e.g. missing generics on relations, `mixed` config returns). Fix by adding `@return` generics docblocks and casting `config()` returns, e.g. in models:

```php
    /** @return HasMany<JournalTransaction, $this> */
    public function transactions(): HasMany
    {
        /** @var class-string<JournalTransaction> $transactionClass */
        $transactionClass = config('journal.models.transaction');

        return $this->hasMany($transactionClass);
    }
```

Apply the same `class-string` pattern to every `config('journal.models.*')` call site (`Journal::ledger()`, `Journal::post()`, `Ledger::journals()`, `Ledger::journalTransactions()`, `HasJournal::journal()`, `HasJournal::initJournal()`, `HasJournalTransactions::journalTransactions()`, `JournalTransaction::journal()`). Iterate until level 6 is clean. If a finding demands a behaviour change, stop and flag it rather than "fixing" silently.

- [ ] **Step 3: Re-run the full suite**

Run: `vendor\bin\pest`
Expected: PASS — analysis fixes must not break tests.

- [ ] **Step 4: Create .github/workflows/ci.yml**

```yaml
name: CI

on:
  push:
    branches: [main, master]
  pull_request:

jobs:
  tests:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4']
    name: Tests / PHP ${{ matrix.php }}
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_sqlite
          coverage: none

      - run: composer install --prefer-dist --no-interaction --no-progress

      - run: vendor/bin/pest

  style:
    runs-on: ubuntu-latest
    name: Pint
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - run: composer install --prefer-dist --no-interaction --no-progress

      - run: vendor/bin/pint --test

  static-analysis:
    runs-on: ubuntu-latest
    name: PHPStan
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - run: composer install --prefer-dist --no-interaction --no-progress

      - run: vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 5: Commit**

```bash
git add .github src
git commit -m "chore: Pint/PHPStan clean-up and CI workflow

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 11: Documentation

**Files:**
- Create: `README.md`
- Create: `UPGRADE.md`
- Create: `CHANGELOG.md`
- Create: `LICENSE.txt`

**Interfaces:**
- Consumes: the final API from Tasks 1–9. Before writing, re-read `src/` — every code sample in the docs must use real, current method signatures.
- Produces: user-facing documentation.

- [ ] **Step 1: Write README.md**

Structure (write real prose; code samples below are the required, tested-against-the-API examples):

1. **Title + intro** — journals and double-entry bookkeeping for Eloquent models; fork lineage credit to scottlaurent/accounting and consilience/accounting.
2. **Requirements** — PHP 8.2+, Laravel 12+.
3. **Installation**

```bash
composer require academe/laravel-journal

php artisan vendor:publish --tag=journal-config
php artisan vendor:publish --tag=journal-migrations
php artisan migrate
```

4. **Quick start**

```php
use Academe\LaravelJournal\Concerns\HasJournal;

class User extends Model
{
    use HasJournal;
}

$user->initJournal('USD');

$transaction = $user->journal->credit(Money::USD(10000), 'Opening credit');
$user->journal->debit(7500);

$balance = $user->journal->currentBalance(); // Money::USD(2500)
```

5. **How it works** — one journal per model instance via the `owner` morph; amounts stored as integer minor units (Money PHP); credits positive, debits negative; `journals.balance` is a cached column kept in sync on every transaction save/delete; a `Money` value must match the journal currency (`CurrencyMismatch` otherwise), a plain `int` means minor units in the journal currency.
6. **Balances** — `currentBalance()`, `balanceOn($date)`, `totalBalance()`, `debitBalanceOn($date)`, `creditBalanceOn($date)`.
7. **Referencing models** — `$transaction->reference()->associate($product)->save();` and the `HasJournalTransactions` trait for the inverse.
8. **Double entry with TransactionGroup**

```php
use Academe\LaravelJournal\TransactionGroup;

$group = TransactionGroup::make()
    ->addTransaction($user->journal, 'credit', Money::USD(50000))
    ->addTransaction($arJournal, 'debit', Money::USD(50000));

$groupUuid = $group->commit();
```

9. **Ledgers** — the three usage scenarios from the original README, rewritten against the current API: (A) simple running balance per model; (B) manual double entry between journals; (C) ledger-enforced double entry with the five `LedgerType` account types, `assignToLedger()`, and `Ledger::currentBalance($currency)` with the accounting-equation guarantee.
10. **Configuration** — `base_currency`, overriding model classes.
11. **Roadmap** — model improvements (tags storage, general-purpose morph on transactions), period checkpoints for fast balances.
12. **Licence** — MIT.

- [ ] **Step 2: Write UPGRADE.md**

Contents:

1. **Class mapping table** — old `Scottlaurent\Accounting\*` name → new `Academe\LaravelJournal\*` name for every public class (use the table from spec §2), including `Services\Accounting::newDoubleEntryTransactionGroup()` → `TransactionGroup::make()` and `ModelTraits\*` → `Concerns\*`.
2. **Config** — `config/accounting.php` → `config/journal.php`; key `model-classes.*` → `models.*` (`journal-transaction` → `transaction`).
3. **Behaviour changes** — currency now validated on posting (`CurrencyMismatch`); group references are persisted (fork silently dropped them); `TransactionCouldNotBeProcessed` carries `$previous`.
4. **Data migration** — a complete copyable migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('accounting_ledgers', 'journal_ledgers');
        Schema::rename('accounting_journals', 'journals');
        Schema::rename('accounting_journal_transactions', 'journal_transactions');

        Schema::table('journals', function ($table) {
            $table->renameColumn('morphed_type', 'owner_type');
            $table->renameColumn('morphed_id', 'owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function ($table) {
            $table->renameColumn('owner_type', 'morphed_type');
            $table->renameColumn('owner_id', 'morphed_id');
        });

        Schema::rename('journal_transactions', 'accounting_journal_transactions');
        Schema::rename('journals', 'accounting_journals');
        Schema::rename('journal_ledgers', 'accounting_ledgers');
    }
};
```

   With a note: run the package's published migrations only on fresh installs — for upgrades use this rename migration instead, and add any missing indexes afterwards (the new schema indexes `post_date`, `transaction_group`, `journal_id`, and the morph column pairs).

- [ ] **Step 3: Write CHANGELOG.md**

```markdown
# Changelog

## 1.0.0 - Unreleased

Initial release of academe/laravel-journal, a modernised conversion of
[consilience/accounting](https://github.com/consilience/accounting)
(itself a fork of scottlaurent/accounting).

### Added
- Laravel 12 / PHP 8.2+ support.
- Currency validation on posting (`CurrencyMismatch`).
- Indexes on morph, journal, post date, and group columns.
- Pest test suite, PHPStan (level 6), Pint, GitHub Actions CI.

### Changed
- Package renamed to `academe/laravel-journal`; namespace `Academe\LaravelJournal`.
- Tables renamed: `journal_ledgers`, `journals`, `journal_transactions`.
- Journal morph columns renamed `morphed_*` -> `owner_*`.
- `Services\Accounting` replaced by `TransactionGroup`.
- Traits moved to `Concerns\HasJournal` / `Concerns\HasJournalTransactions`.
- `TransactionCouldNotBeProcessed` now chains the underlying exception.
- Transaction group references are now persisted (fork dropped them silently).

### Removed
- Deprecated `morphed` relation and `referencesObject()` method.
- Empty `Journal::remove()` stub.
```

- [ ] **Step 4: Write LICENSE.txt**

MIT licence text with:

```
Copyright (c) 2026 Jason Judge / Academe Computing
Copyright (c) 2016-2023 Scott Laurent and contributors
```

- [ ] **Step 5: Verify docs against the code**

For each code sample in README.md and UPGRADE.md, confirm the named classes/methods exist with those signatures in `src/` (`TransactionGroup::make`, `addTransaction`, `commit`, `Journal::credit/debit/currentBalance/balanceOn/totalBalance`, `initJournal`, `assignToLedger`, `Ledger::currentBalance`). Fix any drift.

- [ ] **Step 6: Run the full suite one final time**

Run: `vendor\bin\pest; vendor\bin\pint --test; vendor\bin\phpstan analyse`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add README.md UPGRADE.md CHANGELOG.md LICENSE.txt
git commit -m "docs: README, upgrade guide, changelog, and licence

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Post-plan checklist (not tasks)

- Push to a new GitHub repo `academe/laravel-journal` and submit to Packagist when ready to release (user decision — do not do this without being asked).
- Phase 2 (separate plan): model improvements — tags storage, general-purpose morph on transactions.
- Phase 3 (separate plan): period checkpoints for fast balance calculation.

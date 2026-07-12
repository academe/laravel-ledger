<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Academe\LaravelJournal\Concerns\HasJournal;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal host model used to hang company-level journals on
 * (cash, accounts receivable, income, ...) in tests.
 */
class CompanyJournal extends Model
{
    use HasJournal;

    protected $guarded = [];
}

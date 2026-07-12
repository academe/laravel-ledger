<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Academe\LaravelJournal\Concerns\HasJournalTransactions;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasJournalTransactions;

    protected $guarded = [];
}

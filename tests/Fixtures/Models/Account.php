<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Academe\LaravelJournal\Concerns\HasJournal;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasJournal;

    protected $guarded = [];
}

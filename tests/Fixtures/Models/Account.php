<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Models;

use Academe\LaravelJournal\Concerns\HasJournal;
use Academe\LaravelJournal\Contracts\NamesJournal;
use Illuminate\Database\Eloquent\Model;

class Account extends Model implements NamesJournal
{
    use HasJournal;

    protected $guarded = [];

    public function journalDisplayName(): string
    {
        return $this->name;
    }

    public function journalDescription(): ?string
    {
        return null;
    }
}

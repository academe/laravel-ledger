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

            // A code from an enum registered in journal.ledger_types;
            // StandardLedgerType defines 'asset', 'liability', 'equity',
            // 'income', and 'expense'.
            $table->string('type', 30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_ledgers');
    }
};

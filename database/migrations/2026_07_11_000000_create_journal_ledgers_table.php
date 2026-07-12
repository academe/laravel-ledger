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

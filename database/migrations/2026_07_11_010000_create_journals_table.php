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

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};

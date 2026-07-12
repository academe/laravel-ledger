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

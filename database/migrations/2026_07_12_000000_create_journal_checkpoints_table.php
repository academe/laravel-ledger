<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('journal_id')
                ->index()
                ->constrained('journals');

            // Totals cover all transactions with post_date <= end of this day.
            $table->date('checkpoint_date');

            // Cumulative minor units from the beginning of time through
            // checkpoint_date. Both sides are stored so the one-sided
            // balance methods accelerate too.
            $table->bigInteger('debit_total');
            $table->bigInteger('credit_total');

            // ISO 4217; copied from the journal at creation.
            $table->string('currency_code', 3);

            $table->unique(['journal_id', 'checkpoint_date']);
        });

        Schema::table('journals', function (Blueprint $table) {
            // The journal's latest checkpoint date. Postings dated at or
            // before the end of this day are rejected (PeriodClosed).
            $table->date('locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropColumn('locked_until');
        });

        Schema::dropIfExists('journal_checkpoints');
    }
};

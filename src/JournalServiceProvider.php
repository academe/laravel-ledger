<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class JournalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/journal.php', 'journal');

        $this->app->singleton(JournalModels::class);
        $this->app->singleton(PendingBalanceUpdates::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/journal.php' => config_path('journal.php'),
        ], 'journal-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'journal-migrations');

        // Flush batched balance recomputes just before the outermost
        // transaction commits, so the cache updates commit atomically
        // with the entries they reflect.
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            $this->app->make(PendingBalanceUpdates::class)
                ->flush((string) $event->connectionName);
        });

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $updates = $this->app->make(PendingBalanceUpdates::class);

            // A rollback may have dropped the after-commit net; allow a
            // new one to be registered by the next write.
            $updates->resetNet((string) $event->connectionName);

            // Only a full rollback invalidates the queue: a savepoint
            // rollback leaves earlier work in the outer transaction that
            // still needs its recompute (a spare recompute for a journal
            // whose entries were rolled back is harmless — it recomputes
            // from the rows that actually exist).
            if ($event->connection->transactionLevel() === 0) {
                $updates->discard((string) $event->connectionName);
            }
        });
    }
}

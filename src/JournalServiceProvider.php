<?php

declare(strict_types=1);

namespace Academe\LaravelJournal;

use Illuminate\Support\ServiceProvider;

class JournalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/journal.php', 'journal');

        $this->app->singleton(JournalModels::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/journal.php' => config_path('journal.php'),
        ], 'journal-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'journal-migrations');
    }
}

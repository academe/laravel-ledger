<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests;

use Academe\LaravelJournal\JournalServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            JournalServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (is_dir(__DIR__.'/Fixtures/migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        }
    }
}

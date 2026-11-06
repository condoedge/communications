<?php

namespace Condoedge\Communications\Tests;

use Condoedge\Communications\CondoedgeCommunicationServiceProvider;
use Condoedge\Utils\CondoedgeUtilsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Kompo\Auth\KompoAuthServiceProvider;
use Kompo\KompoServiceProvider;
use Lab404\Impersonate\ImpersonateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\default_migration_path;

/**
 * Base test case for condoedge/communications.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ImpersonateServiceProvider::class,
            KompoServiceProvider::class,
            CondoedgeUtilsServiceProvider::class,
            KompoAuthServiceProvider::class,
            CondoedgeCommunicationServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->bind('team-model', fn () => config('kompo-auth.team-model-namespace'));

        Config::set('kompo-auth.security.bypass-security', true);
        Config::set('kompo-auth.load-migrations', true);
        Config::set('queue.default', 'sync');
        Config::set('mail.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        if (RefreshDatabaseState::$migrated === false) {
            $this->loadMigrationsFrom(default_migration_path());
        }

        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}

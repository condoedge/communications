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
 *
 * Providers are registered ONCE, by Testbench. Unlike kompo/auth's and condoedge/finance's
 * TestCase, this one does NOT re-instantiate and re-boot them in setUp(): this package's
 * register() calls ContentReplacer::addParsers(), which is additive on a shared singleton, so a
 * second pass installs BraceMentionParser and HtmlMentionParser twice. boot() also includes
 * src/Helpers/mail-elements.php, whose four functions carry no function_exists guard.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Belt-and-braces: testbench.yaml already lists these, but declaring them here means a run
     * that bypasses the yaml (a CI job invoking phpunit directly) boots the same chain. Testbench
     * de-duplicates by class name, so this does not double-register.
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Listed explicitly rather than left to composer auto-discovery, because Testbench
            // disables discovery entirely for a plain (non-Workbench) TestCase:
            // CreatesApplication::ignorePackageDiscoveriesFrom() returns ['*']. In a real app this
            // provider is auto-discovered, so kompo/auth's routes/web.php line 10 can call
            // Route::impersonate() — a macro this provider installs in register(). Without it every
            // test in the suite dies at boot with "Attribute [impersonate] does not exist", which
            // reads like a routing bug rather than a missing provider. It must precede
            // KompoAuthServiceProvider so the macro exists before auth's booted() callback loads
            // those routes.
            ImpersonateServiceProvider::class,
            KompoServiceProvider::class,
            CondoedgeUtilsServiceProvider::class,
            KompoAuthServiceProvider::class,
            CondoedgeCommunicationServiceProvider::class,
        ];
    }

    /**
     * Bindings that must exist BEFORE any provider boots. kompo/auth resolves 'team-model'
     * during boot.
     */
    protected function defineEnvironment($app): void
    {
        $app->bind('team-model', fn () => config('kompo-auth.team-model-namespace'));

        Config::set('kompo-auth.security.bypass-security', true);
        Config::set('kompo-auth.load-migrations', true);
        Config::set('queue.default', 'sync');
        Config::set('mail.default', 'array');
    }

    /**
     * Package migrations come from each provider's own loadMigrationsFrom(). Only test-only
     * fixture tables belong here.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Laravel's stock users/sessions/cache tables. kompo/auth only ever ADDS columns to users
        // (2014_10_12_000100_add_base_columns_to_users_table.php) and foreign-keys onto it from
        // email_requests — it never creates it, so without this the very first auth migration dies
        // with "Failed to open the referenced table 'users'". Testbench's WithLaravelMigrations
        // trait is not usable here: it no-ops unless testbench.yaml declares workbench.install,
        // which this package does not use. Registered via loadMigrationsFrom (not
        // loadLaravelMigrations) so these run in the SAME migrator pass as the package migrations,
        // where the 0001_01_01_* filenames sort ahead of auth's 2014_* ones. A separate pass would
        // not guarantee that ordering.
        //
        // Guarded on RefreshDatabaseState::$migrated, mirroring the same check inside Testbench's
        // own WithLaravelMigrations trait. Only on the first test does loadMigrationsFrom() merely
        // register the path for the upcoming migrate:fresh; on every later test it instead spins up
        // a MigrateProcessor that registers a tearDown ROLLBACK of this path, and that rollback
        // drops `users` while auth's email_requests still foreign-keys onto it — MySQL errno 3730,
        // "Cannot drop table 'users' referenced by a foreign key constraint". The first test would
        // pass and every subsequent one fail, which looks like test pollution rather than a
        // migration-path bug.
        if (RefreshDatabaseState::$migrated === false) {
            $this->loadMigrationsFrom(default_migration_path());
        }

        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}

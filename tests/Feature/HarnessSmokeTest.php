<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Services\Dispatch\CommunicationDispatchServiceContract;
use Condoedge\Communications\Services\TemplateSeeding\TemplateSeedingServiceContract;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * The harness itself, not the package's behaviour.
 *
 * These three assertions each catch a different way the Testbench setup can be silently wrong:
 * the provider chain not booting, the MySQL-only migrations not running, and the config not
 * merging. A reminder test failing for one of those reasons is very hard to read.
 */
class HarnessSmokeTest extends TestCase
{
    public function test_the_package_provider_registered_and_bound_its_services()
    {
        $this->assertTrue(app()->bound('communication-variables-manager'));

        $this->assertInstanceOf(
            TemplateSeedingServiceContract::class,
            app(TemplateSeedingServiceContract::class),
        );

        $this->assertInstanceOf(
            CommunicationDispatchServiceContract::class,
            app(CommunicationDispatchServiceContract::class),
        );
    }

    public function test_the_package_migrations_ran_against_mysql()
    {
        // communication_template_groups carries the MySQL-only prefix indexes, and
        // communication_recipients foreign-keys onto kompo/auth's teams table. If either the
        // driver or the provider order were wrong, one of these would be missing.
        $this->assertTrue(Schema::hasTable('communication_template_groups'));
        $this->assertTrue(Schema::hasTable('communication_sendings'));
        $this->assertTrue(Schema::hasTable('communication_recipients'));
        $this->assertTrue(Schema::hasTable('teams'));
        $this->assertTrue(Schema::hasColumn('communication_template_groups', 'team_id'));
    }

    public function test_the_package_config_merged()
    {
        $this->assertIsArray(config('kompo-communications.triggers'));

        $this->assertContains(
            \Condoedge\Communications\Triggers\ManualTrigger::class,
            config('kompo-communications.triggers'),
        );
    }
}

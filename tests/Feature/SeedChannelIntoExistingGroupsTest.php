<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Models\NotificationTemplate;
use Condoedge\Communications\Services\TemplateSeeding\TemplateSeedingServiceContract;
use Condoedge\Communications\Tests\Stubs\ButtonHandlerTrigger;
use Condoedge\Communications\Tests\Stubs\TitleOnlyTrigger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Adding one channel to groups seeded before that channel existed, without touching anything else.
 */
class SeedChannelIntoExistingGroupsTest extends TestCase
{
    /** @var array<string> absolute paths written into the Testbench skeleton, removed in tearDown */
    protected array $writtenViews = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('kompo.locales', ['en' => 'English', 'fr' => 'Français']);
        Config::set('app.locale', 'en');
        Config::set('kompo-communications.triggers', [TitleOnlyTrigger::class, ButtonHandlerTrigger::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenViews as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    protected function service(): TemplateSeedingServiceContract
    {
        return app(TemplateSeedingServiceContract::class);
    }

    protected function templateOf(CommunicationTemplateGroup $group, CommunicationType $type, bool $withTrashed = false)
    {
        return $group->communicationTemplates()->when($withTrashed, fn ($q) => $q->withTrashed())->where('type', $type->value)->first();
    }

    public function test_a_fresh_seed_creates_every_channel_with_a_stub_even_after_one_without()
    {
        // EMAIL (1) and PUSH (5) have stubs; SMS, DATABASE and TASK in between have none.
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH);

        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertNotNull($this->templateOf($group, CommunicationType::EMAIL));
        $this->assertNotNull(
            $this->templateOf($group, CommunicationType::PUSH),
            'a stubless channel earlier in the list ended the seeding loop',
        );
    }

    public function test_the_channel_is_added_to_a_baseline_seeded_before_it_existed()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertNull($this->templateOf($group, CommunicationType::PUSH), 'precondition: the group was seeded without push');

        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH);

        $touched = $this->service()->seedChannelIntoExistingGroups(CommunicationType::PUSH);

        $this->assertSame([$group->id], $touched->pluck('id')->all());

        $push = $this->templateOf($group, CommunicationType::PUSH);

        $this->assertNotNull($push);
        $this->assertSame(['en' => 'push-content-en', 'fr' => 'push-content-fr'], $push->getTranslations('content'));
        $this->assertSame(['en' => 'name-en', 'fr' => 'name-fr'], $push->getTranslations('subject'));
    }

    public function test_a_subject_stub_overrides_the_trigger_name_for_that_channel_only()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH, subject: true);

        $this->service()->seedChannelIntoExistingGroups(CommunicationType::PUSH);

        $this->assertSame(
            ['en' => 'push-subject-en', 'fr' => 'push-subject-fr'],
            $this->templateOf($group, CommunicationType::PUSH)->getTranslations('subject'),
        );

        $this->assertSame(
            ['en' => 'name-en', 'fr' => 'name-fr'],
            $this->templateOf($group, CommunicationType::EMAIL)->getTranslations('subject'),
            'the email template must be left exactly as seeded',
        );
    }

    public function test_a_team_copy_receives_the_channel_too()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $baseline = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);
        $teamCopy = $baseline->copyForTeam(
            DB::table('teams')->insertGetId(['team_name' => 'Seeding probe', 'created_at' => now(), 'updated_at' => now()]),
        );

        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH);

        $touched = $this->service()->seedChannelIntoExistingGroups(CommunicationType::PUSH);

        $this->assertEqualsCanonicalizing([$baseline->id, $teamCopy->id], $touched->pluck('id')->all());
        $this->assertNotNull($this->templateOf($teamCopy, CommunicationType::PUSH));
    }

    public function test_a_group_that_already_has_the_channel_even_soft_deleted_is_left_alone()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH);
        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $push = $this->templateOf($group, CommunicationType::PUSH);
        $push->delete();

        $touched = $this->service()->seedChannelIntoExistingGroups(CommunicationType::PUSH);

        $this->assertSame([], $touched->all());
        $this->assertSame(1, $group->communicationTemplates()->withTrashed()->where('type', CommunicationType::PUSH->value)->count());
    }

    public function test_a_one_off_manual_group_is_skipped()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);
        $group->direct_usage = true;
        $group->save();

        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH);

        $this->assertSame([], $this->service()->seedChannelIntoExistingGroups(CommunicationType::PUSH)->all());
    }

    public function test_a_group_whose_trigger_has_no_stub_for_the_channel_is_not_reported_as_touched()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertSame([], $this->service()->seedChannelIntoExistingGroups(CommunicationType::PUSH)->all());
    }

    public function test_adding_the_database_channel_applies_the_same_defaults_as_a_fresh_seed()
    {
        $this->writeStubsFor(ButtonHandlerTrigger::class, CommunicationType::EMAIL);
        $group = CommunicationTemplateGroup::createForTrigger(ButtonHandlerTrigger::class);

        $this->writeStubsFor(ButtonHandlerTrigger::class, CommunicationType::DATABASE);

        $this->service()->seedChannelIntoExistingGroups(CommunicationType::DATABASE);

        $database = $this->templateOf($group, CommunicationType::DATABASE);

        $this->assertNotNull($database);
        $this->assertSame(
            ButtonHandlerTrigger::HANDLER,
            NotificationTemplate::forCommunication($database->id)->first()?->custom_button_handler,
            'a channel added later must get the trigger\'s default button handler, as seedForTrigger() gives it',
        );
    }

    // COMMAND

    public function test_the_command_accepts_the_channel_by_name_or_by_value()
    {
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::EMAIL);
        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);
        $this->writeStubsFor(TitleOnlyTrigger::class, CommunicationType::PUSH);

        $this->artisan('communications:seed-templates', ['--add-channel' => 'push'])
            ->expectsOutputToContain('Channel PUSH added to 1 existing group(s)')
            ->assertSuccessful();

        $this->templateOf($group, CommunicationType::PUSH)->forceDelete();

        $this->artisan('communications:seed-templates', ['--add-channel' => '5'])
            ->expectsOutputToContain('Channel PUSH added to 1 existing group(s)')
            ->assertSuccessful();
    }

    public function test_the_command_refuses_an_unknown_channel()
    {
        $this->artisan('communications:seed-templates', ['--add-channel' => 'fax'])
            ->expectsOutputToContain('Unknown channel: fax')
            ->assertFailed();
    }

    protected function writeStubsFor(string $trigger, CommunicationType $type, bool $subject = false): void
    {
        $slug = \Str::slug(\Str::snake(class_basename($trigger)));
        $directory = resource_path('views/stubs/communication-templates');

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $prefix = strtolower($type->name);

        foreach (array_keys(config('kompo.locales')) as $locale) {
            $path = "{$directory}/default-{$slug}-{$type->value}-{$locale}.blade.php";
            file_put_contents($path, "{$prefix}-content-{$locale}");
            $this->writtenViews[] = $path;

            if ($subject) {
                $path = "{$directory}/default-{$slug}-{$type->value}-subject-{$locale}.blade.php";
                file_put_contents($path, "{$prefix}-subject-{$locale}");
                $this->writtenViews[] = $path;
            }
        }
    }
}

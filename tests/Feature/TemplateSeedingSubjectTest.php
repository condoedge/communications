<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Tests\Stubs\EmptySubjectTrigger;
use Condoedge\Communications\Tests\Stubs\NamelessTrigger;
use Condoedge\Communications\Tests\Stubs\SubjectOverridingTrigger;
use Condoedge\Communications\Tests\Stubs\TitleOnlyTrigger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * getName() serves the admin-facing title; getSubject(), when a trigger declares it, serves the
 * recipient-facing subject. These tests hold the two apart in both directions, because collapsing
 * them is invisible until a real deployment seeds a live wording change.
 */
class TemplateSeedingSubjectTest extends TestCase
{
    /** @var array<string> absolute paths written into the Testbench skeleton, removed in tearDown */
    protected array $writtenViews = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('kompo.locales', ['en' => 'English', 'fr' => 'Français']);

        Config::set('app.locale', 'en');
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenViews as $path) {
            @unlink($path);
        }

        $this->writtenViews = [];

        parent::tearDown();
    }

    public function test_a_trigger_without_get_subject_still_seeds_its_name_as_the_subject()
    {
        $this->writeContentStubsFor(TitleOnlyTrigger::class);

        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertSame(
            ['en' => 'name-en', 'fr' => 'name-fr'],
            $this->seededSubject($group),
        );
    }

    public function test_a_trigger_declaring_get_subject_seeds_that_instead_of_its_name()
    {
        $this->writeContentStubsFor(SubjectOverridingTrigger::class);

        $group = CommunicationTemplateGroup::createForTrigger(SubjectOverridingTrigger::class);

        $this->assertSame(
            ['en' => 'subject-en', 'fr' => 'subject-fr'],
            $this->seededSubject($group),
        );
    }

    public function test_an_empty_get_subject_falls_back_to_the_name()
    {
        $this->writeContentStubsFor(EmptySubjectTrigger::class);

        $group = CommunicationTemplateGroup::createForTrigger(EmptySubjectTrigger::class);

        $this->assertSame(
            ['en' => 'name-en', 'fr' => 'name-fr'],
            $this->seededSubject($group),
            'an empty getSubject() seeded an empty subject instead of falling back to getName()',
        );
    }

    public function test_each_locale_gets_its_own_subject()
    {
        $this->writeContentStubsFor(SubjectOverridingTrigger::class);

        app()->setLocale('fr');

        $subject = $this->seededSubject(
            CommunicationTemplateGroup::createForTrigger(SubjectOverridingTrigger::class)
        );

        $this->assertSame(
            ['en' => 'subject-en', 'fr' => 'subject-fr'],
            $subject,
            'the seeded map followed the ambient locale — the subject was resolved outside the locale closure',
        );

        $this->assertNotSame(
            $subject['en'],
            $subject['fr'],
            'both locales got the same wording — the subject was resolved outside the locale closure',
        );

        $this->assertSame('fr', app()->getLocale(), 'seeding leaked its last locale');
    }

    public function test_the_group_title_still_comes_from_get_name()
    {
        $this->writeContentStubsFor(SubjectOverridingTrigger::class);

        $group = CommunicationTemplateGroup::createForTrigger(SubjectOverridingTrigger::class);

        $this->assertSame('name-' . app()->getLocale(), $group->title);
        $this->assertStringStartsNotWith('subject-', $group->title);
    }

    public function test_trigger_name_still_reports_drift_for_an_unloadable_class()
    {
        $this->assertSame('—', CommunicationTemplateGroup::triggerName('App\\Gone\\Trigger'));
        $this->assertSame('—', CommunicationTemplateGroup::triggerName(null));

        $this->assertSame('name-en', CommunicationTemplateGroup::triggerName(SubjectOverridingTrigger::class));

        $this->assertSame('—', CommunicationTemplateGroup::triggerName(NamelessTrigger::class));
    }

    protected function seededSubject(CommunicationTemplateGroup $group): array
    {
        $template = $group->communicationTemplates()
            ->where('type', CommunicationType::EMAIL->value)
            ->first();

        $this->assertNotNull($template, 'no EMAIL template was seeded — the content stubs did not resolve');

        return $template->getTranslations('subject');
    }

    protected function writeContentStubsFor(string $trigger): void
    {
        $slug = \Str::slug(\Str::snake(class_basename($trigger)));
        $directory = resource_path('views/stubs/communication-templates');

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        foreach (array_keys(config('kompo.locales')) as $locale) {
            $path = $directory . '/default-' . $slug . '-' . CommunicationType::EMAIL->value . '-' . $locale . '.blade.php';

            file_put_contents($path, 'content-' . $locale);

            $this->writtenViews[] = $path;
        }
    }
}

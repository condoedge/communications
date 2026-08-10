<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Tests\Stubs\SubjectOverridingTrigger;
use Condoedge\Communications\Tests\Stubs\TitleOnlyTrigger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * getName() serves the admin-facing TITLE; getSubject(), when a trigger declares it, serves the
 * recipient-facing SUBJECT. These tests hold the two apart in both directions, because collapsing
 * them is invisible until a real deployment seeds a live wording change.
 */
class TemplateSeedingSubjectTest extends TestCase
{
    /** @var array<string> absolute paths written into the Testbench skeleton, removed in tearDown */
    protected array $writtenViews = [];

    protected function setUp(): void
    {
        parent::setUp();

        // createForTrigger() walks array_keys(config('kompo.locales')), and kompo ships that list
        // EMPTY. Without this every seeded subject and content map is [] and the whole method
        // returns null — the tests would pass vacuously by asserting on a group that was never
        // given a template.
        Config::set('kompo.locales', ['en' => 'English', 'fr' => 'Français']);

        // The 'name-en' / '—' string literals below depend on this being the ambient locale; the
        // harness never pins it, so they currently ride on the Testbench default and both hosts of
        // this package are French-first.
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
        // The SISC-compatibility case. All 34 of that host's registered triggers look like this
        // stub, and none may need an edit.
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

    /**
     * The subject must be resolved INSIDE executeCallbackInLocale(). Hoisting the call out of the
     * closure gives every locale the wording of whichever locale happened to be active at seed
     * time — a French-only mailing list quietly receiving English subject lines.
     *
     * Seeded here under a NON-DEFAULT ambient locale, which is what the exact-map assertion above
     * cannot reach: every other test in this file runs at 'en', which is also the first configured
     * locale, so a hoisted resolve would produce the correct map there by coincidence. The trailing
     * assertion states the invariant that seeding must leave the ambient locale where it found it —
     * a seeder that leaks its last locale silently retints everything that runs after it in the
     * same request. It is a statement, not a trap: 'fr' is also the LAST configured locale, so
     * executeCallbackInLocale() losing its finally-restore would leave 'fr' behind anyway. It only
     * starts biting if the ambient locale here ever stops matching the last configured one.
     */
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

    /**
     * The three TITLE reads must keep reading getName(). If the group title were to follow the
     * subject, the admin template list would stop showing what the trigger IS and start showing
     * what its email says.
     */
    public function test_the_group_title_still_comes_from_get_name()
    {
        $this->writeContentStubsFor(SubjectOverridingTrigger::class);

        $group = CommunicationTemplateGroup::createForTrigger(SubjectOverridingTrigger::class);

        $this->assertSame('name-' . app()->getLocale(), $group->title);
        $this->assertStringStartsNotWith('subject-', $group->title);
    }

    /**
     * triggerName()'s '—' bucket means CONFIGURATION DRIFT: a class named in config that no longer
     * exists. It is a display fallback, not a subject fallback, and must not start answering for
     * anything else.
     */
    public function test_trigger_name_still_reports_drift_for_an_unloadable_class()
    {
        $this->assertSame('—', CommunicationTemplateGroup::triggerName('App\\Gone\\Trigger'));
        $this->assertSame('—', CommunicationTemplateGroup::triggerName(null));

        $this->assertSame('name-en', CommunicationTemplateGroup::triggerName(SubjectOverridingTrigger::class));
    }

    /**
     * The seeded EMAIL template's raw translation map. Read through getTranslations() rather than
     * ->subject, which would collapse the map to the active locale and make the two-locale
     * assertion unwritable.
     */
    protected function seededSubject(CommunicationTemplateGroup $group): array
    {
        $template = $group->communicationTemplates()
            ->where('type', CommunicationType::EMAIL->value)
            ->first();

        $this->assertNotNull($template, 'no EMAIL template was seeded — the content stubs did not resolve');

        return $template->getTranslations('subject');
    }

    /**
     * createForTrigger() skips any channel with no default-content blade, so a trigger with no
     * stub files seeds nothing at all and every assertion below would vacuously pass on a null
     * template. Write the EMAIL stub for both locales into the Testbench skeleton's resources
     * directory — resource_path() is hard-coded at the seeding site, so there is no test hook to
     * redirect it, and these are removed again in tearDown.
     */
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

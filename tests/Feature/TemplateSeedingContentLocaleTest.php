<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Tests\Stubs\TitleOnlyTrigger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * Seeded BODIES must resolve their translations in the locale they are stored under, not in whatever
 * locale happened to be active when the seeder ran.
 *
 * THE FAILURE THIS PINS. Picking the per-locale stub FILE is not the same as rendering it in that
 * locale. The file supplies the prose, so an English body reads as English and looks finished — but
 * every __() evaluated during the render resolves against the ambient locale, and mention chips are
 * built exactly that way (HtmlMentionParser::buildVar() emits
 * '<span data-mention="ID">' . __($var[1]) . '</span>'). Seeding under a French default therefore
 * stamped English templates with English sentences and FRENCH chip labels. It hid well: the row is a
 * complete translation map, the prose is right, and the only person who ever sees it is the admin who
 * later opens that template in the editor. It was also permanent — baselineExists() asks whether the
 * GROUP exists, so re-seeding answers "Nothing to do." and never re-derives a body.
 *
 * WHY THIS TEST WRITES THE SAME BYTES TO BOTH STUB FILES. The sibling helper in
 * TemplateSeedingSubjectTest writes 'content-en' / 'content-fr' — different text per file, so its
 * assertions pass whether or not the render switches locale, and no existing test could fail on this
 * bug. Here both files carry the IDENTICAL body, so the locale of the render is the only thing that
 * can make the two stored values differ. That is what makes the assertion falsifiable.
 */
class TemplateSeedingContentLocaleTest extends TestCase
{
    /** @var array<string> absolute paths written into the Testbench skeleton, removed in tearDown */
    protected array $writtenViews = [];

    protected function setUp(): void
    {
        parent::setUp();

        // createForTrigger() walks array_keys(config('kompo.locales')) and kompo ships that list
        // EMPTY, which would seed no template at all and pass every assertion vacuously.
        Config::set('kompo.locales', ['en' => 'English', 'fr' => 'Français']);

        // Deliberately pinned to ONE locale, and it must not be neutral: this is the ambient locale
        // the buggy render leaked into every stored translation. Leaving it to the Testbench default
        // would make the test's outcome depend on a value no assertion mentions.
        Config::set('app.locale', 'en');

        app('translator')->addLines(['seed-locale-probe.chip' => 'chip-en'], 'en');
        app('translator')->addLines(['seed-locale-probe.chip' => 'chip-fr'], 'fr');
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenViews as $path) {
            @unlink($path);
        }

        $this->writtenViews = [];

        parent::tearDown();
    }

    public function test_each_seeded_body_resolves_its_translations_in_its_own_locale()
    {
        $this->writeIdenticalContentStubsFor(TitleOnlyTrigger::class);

        $group = CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertSame(
            ['en' => 'body chip-en', 'fr' => 'body chip-fr'],
            $this->seededContent($group),
        );
    }

    public function test_the_ambient_locale_is_restored_after_seeding()
    {
        // executeCallbackInLocale restores in a finally, but the guarantee is worth pinning here
        // rather than in the helper's own suite: seeding runs inside a deploy step, and a seeder
        // that leaked a locale would mistranslate everything that ran after it in the same process,
        // with nothing pointing back at the seeder.
        $this->writeIdenticalContentStubsFor(TitleOnlyTrigger::class);

        CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertSame('en', app()->getLocale());
    }

    /**
     * Both locales get BYTE-IDENTICAL stub files. Any difference in the stored result can then only
     * have come from the locale the render ran in.
     */
    protected function writeIdenticalContentStubsFor(string $trigger): void
    {
        $slug = \Str::slug(\Str::snake(class_basename($trigger)));
        $directory = resource_path('views/stubs/communication-templates');

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        foreach (array_keys(config('kompo.locales')) as $locale) {
            $path = $directory . '/default-' . $slug . '-' . CommunicationType::EMAIL->value . '-' . $locale . '.blade.php';

            file_put_contents($path, "body {{ __('seed-locale-probe.chip') }}");

            $this->writtenViews[] = $path;
        }
    }

    protected function seededContent(CommunicationTemplateGroup $group): array
    {
        $template = $group->communicationTemplates()
            ->where('type', CommunicationType::EMAIL->value)
            ->first();

        $this->assertNotNull($template, 'no EMAIL template was seeded — the content stubs did not resolve');

        return $template->getTranslations('content');
    }
}

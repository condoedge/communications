<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Tests\Stubs\TitleOnlyTrigger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * Seeded bodies must resolve their translations in the locale they are stored under, not in whatever
 * locale happened to be active when the seeder ran.
 */
class TemplateSeedingContentLocaleTest extends TestCase
{
    /** @var array<string> absolute paths written into the Testbench skeleton, removed in tearDown */
    protected array $writtenViews = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('kompo.locales', ['en' => 'English', 'fr' => 'Français']);

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
        $this->writeIdenticalContentStubsFor(TitleOnlyTrigger::class);

        CommunicationTemplateGroup::createForTrigger(TitleOnlyTrigger::class);

        $this->assertSame('en', app()->getLocale());
    }

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

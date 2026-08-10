<?php

namespace Condoedge\Communications\Tests\Unit;

use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts\MentionParserInterface;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer;
use Condoedge\Communications\Tests\TestCase;

/**
 * Guards the one deliberate deviation from kompo/auth's TestCase.
 *
 * CondoedgeCommunicationServiceProvider::register() calls ContentReplacer::addParsers(), which
 * appends to a shared singleton. auth's and finance's TestCase re-run register() by hand in
 * setUp(); doing that here would install both parsers twice, and every mention in every template
 * would be replaced twice. Nothing else in the suite would notice.
 */
class ContentReplacerParsersRegisteredOnceTest extends TestCase
{
    public function test_the_mention_parsers_are_installed_exactly_once()
    {
        // The FACADE's instance, not app()'s. A second register() rebinds the singleton, which drops
        // the container's resolved copy (Container::bind -> dropStaleInstances), while the facade
        // keeps its cached one (Facade::$cached). addParsers() therefore lands on the facade's object
        // and app() would hand back a fresh, empty one — the gate would inspect the wrong instance and
        // never see the duplication it exists to report.
        $replacer = ContentReplacer::getFacadeRoot();

        // The named property, not a scan: reflection is needed only because MessageContentReplacer
        // exposes setParsers()/addParsers() and no getter. Scanning every property instead reaches
        // $text (typed, no default, declared after $parsers) on any run where $parsers is empty, and
        // dies with "must not be accessed before initialization" instead of the assertion message below.
        $property = new \ReflectionProperty(MessageContentReplacer::class, 'parsers');
        $property->setAccessible(true);

        $parsers = $property->getValue($replacer);

        $this->assertNotEmpty($parsers, 'the provider never installed the mention parsers');
        $this->assertContainsOnlyInstancesOf(MentionParserInterface::class, $parsers);

        $classes = collect($parsers)->map(fn ($p) => get_class($p));

        $this->assertCount(
            $classes->unique()->count(),
            $classes,
            'a parser is registered twice — the provider was registered twice',
        );
    }
}

<?php

namespace Condoedge\Communications\Tests\Unit;

use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts\MentionParserInterface;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer;
use Condoedge\Communications\Tests\TestCase;

/**
 * Guards the one deliberate deviation from kompo/auth's TestCase.
 */
class ContentReplacerParsersRegisteredOnceTest extends TestCase
{
    public function test_the_mention_parsers_are_installed_exactly_once()
    {
        $replacer = ContentReplacer::getFacadeRoot();

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

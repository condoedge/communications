<?php

namespace Condoedge\Communications\Tests\Unit;

use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Facades\Variables;
use Condoedge\Communications\Tests\TestCase;

/**
 * A replaced value is text, whatever it contains.
 */
class ContentReplacerLiteralValueTest extends TestCase
{
    public function test_dollar_and_backslash_sequences_in_a_value_are_rendered_as_typed()
    {
        $value = 'Win $1000 for the team, $25 off, \1 and ${1} too';

        Variables::setVariables([
            'test' => [['prize', 'Prize', '', false]],
        ]);

        ContentReplacer::setHandlers([
            'prize' => fn () => $value,
        ]);

        $text = implode(' | ', [
            '<p>A <span class="mention" data-mention="prize">Prize</span></p>',
            'B `Prize`',
            'C {{prize}}',
        ]);

        $rendered = ContentReplacer::setText($text)->injectContext([])->replace();

        $this->assertSame("<p>A {$value}</p> | B {$value} | C {$value}", $rendered);
    }
}

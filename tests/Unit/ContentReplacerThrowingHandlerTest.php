<?php

namespace Condoedge\Communications\Tests\Unit;

use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Facades\Variables;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * One variable that cannot be resolved must not cost the recipient the whole message.
 */
class ContentReplacerThrowingHandlerTest extends TestCase
{
    public function test_a_throwing_handler_renders_empty_and_the_rest_of_the_message_still_renders()
    {
        Log::spy();

        Variables::setVariables([
            'test' => [
                ['broken', 'Broken', '', false],
                ['greeting', 'Greeting', '', false],
            ],
        ]);

        ContentReplacer::setHandlers([
            'broken' => function () {
                throw new \Error('null on a model that was deleted');
            },
            'greeting' => fn () => 'Hello',
        ]);

        $text = '<p><span class="mention" data-mention="greeting">Greeting</span>, '
            . 'here is <span class="mention" data-mention="broken">Broken</span>.</p>';

        $rendered = ContentReplacer::setText($text)
            ->injectContext(['trigger' => 'App\\Events\\Probe'])
            ->replace();

        $this->assertSame('<p>Hello, here is .</p>', $rendered);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                $this->assertSame('broken', $context['variable']);
                $this->assertSame('App\\Events\\Probe', $context['trigger']);
                $this->assertSame(['trigger'], $context['context_keys']);
                $this->assertInstanceOf(\Error::class, $context['exception']);

                return true;
            });
    }
}

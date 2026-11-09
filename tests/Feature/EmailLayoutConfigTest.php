<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Which mailable an email renders through: the host's configured layout, unless the event names one.
 */
class EmailLayoutConfigTest extends TestCase
{
    protected function dispatch(array $params = [])
    {
        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Layout probe', 'fr' => 'Layout probe']);
        $template->content = json_encode(['en' => '<p>Layout probe</p>', 'fr' => '<p>Layout probe</p>']);

        return CommunicationType::EMAIL->handler($template)
            ->notify(collect([new LayoutProbeRecipient('one@example.test')]), $params);
    }

    public function test_the_package_default_is_its_own_layout()
    {
        Mail::fake();

        $this->dispatch();

        Mail::assertSent(DefaultLayoutEmailCommunicable::class);
    }

    public function test_the_configured_layout_is_the_one_every_email_renders_through()
    {
        Mail::fake();
        Config::set('kompo-communications.email-layout', HostEmailLayout::class);

        $this->dispatch();

        Mail::assertSent(HostEmailLayout::class);
    }

    public function test_a_layout_passed_by_the_event_wins_over_the_configured_one()
    {
        Mail::fake();
        Config::set('kompo-communications.email-layout', HostEmailLayout::class);

        $this->dispatch(['layout' => DefaultLayoutEmailCommunicable::class]);

        Mail::assertNotSent(HostEmailLayout::class);
        Mail::assertSent(fn (DefaultLayoutEmailCommunicable $mailable) => get_class($mailable) === DefaultLayoutEmailCommunicable::class);
    }
}

class HostEmailLayout extends DefaultLayoutEmailCommunicable
{
}

class LayoutProbeRecipient implements EmailCommunicable
{
    public function __construct(private string $email)
    {
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getContextKey()
    {
        return 'layout_probe';
    }

    public function label()
    {
        return 'Layout probe';
    }

    public function scopeValidForCommunication($query)
    {
        throw new \LogicException('not queryable');
    }

    public function scopeSearch($query, $search)
    {
        throw new \LogicException('not queryable');
    }
}

<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationSendingRecipient;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Mail;

/**
 * A send marked `unrecorded` is delivered and writes nothing to the send log.
 */
class UnrecordedSendTest extends TestCase
{
    protected function template(): CommunicationTemplate
    {
        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->subject = json_encode(['en' => 'Unrecorded probe']);
        $template->content = json_encode(['en' => '<p>Unrecorded probe</p>']);
        $template->save();

        return $template;
    }

    public function test_an_unrecorded_send_is_mailed_and_writes_no_row(): void
    {
        Mail::fake();

        $sendings = CommunicationSending::count();
        $rows = CommunicationSendingRecipient::count();

        $result = $this->template()->notify(
            collect([new UnrecordedProbeRecipient('tester@example.test')]),
            ['unrecorded' => true],
        );

        $this->assertNull($result);
        Mail::assertSent(DefaultLayoutEmailCommunicable::class, fn ($mailable) => $mailable->hasTo('tester@example.test'));
        $this->assertSame($sendings, CommunicationSending::count());
        $this->assertSame($rows, CommunicationSendingRecipient::count());
    }

    public function test_a_send_without_the_marker_is_recorded(): void
    {
        Mail::fake();

        $sending = $this->template()->notify(collect([new UnrecordedProbeRecipient('someone@example.test')]));

        $this->assertNotNull($sending);
        $this->assertSame(1, $sending->recipients()->count());
    }
}

class UnrecordedProbeRecipient implements EmailCommunicable
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
        return 'unrecorded_probe';
    }

    public function label()
    {
        return 'Unrecorded probe';
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

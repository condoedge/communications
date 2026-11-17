<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Mail;

/**
 * A recipient can carry params of its own, read by its message only.
 */
class RecipientOwnParamsTest extends TestCase
{
    protected function paramsByAddress(array $communicables, array $params): array
    {
        Mail::fake();

        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Params probe']);
        $template->content = json_encode(['en' => '<p>Params probe</p>']);

        CommunicationType::EMAIL->handler($template)->notify(collect($communicables), $params);

        return Mail::sent(DefaultLayoutEmailCommunicable::class)
            ->mapWithKeys(fn ($mailable) => [$mailable->to[0]['address'] => $mailable->params])
            ->all();
    }

    public function test_each_message_reads_its_own_recipients_params_over_the_sends(): void
    {
        $params = $this->paramsByAddress([
            RecipientOverride::for(new OwnParamsProbeRecipient('a@example.test'))->withParams(['subject_matter' => 'a']),
            RecipientOverride::for(new OwnParamsProbeRecipient('b@example.test'))->withParams(['subject_matter' => 'b']),
        ], ['subject_matter' => 'shared', 'other' => 'kept']);

        $this->assertSame('a', $params['a@example.test']['subject_matter']);
        $this->assertSame('b', $params['b@example.test']['subject_matter']);
        $this->assertSame('kept', $params['a@example.test']['other']);
    }

    public function test_a_recipients_params_do_not_reach_the_next_recipient(): void
    {
        $params = $this->paramsByAddress([
            RecipientOverride::for(new OwnParamsProbeRecipient('a@example.test'))->withParams(['only_mine' => 'a']),
            RecipientOverride::for(new OwnParamsProbeRecipient('b@example.test')),
            new OwnParamsProbeRecipient('c@example.test'),
        ], []);

        $this->assertSame('a', $params['a@example.test']['only_mine']);
        $this->assertArrayNotHasKey('only_mine', $params['b@example.test']);
        $this->assertArrayNotHasKey('only_mine', $params['c@example.test']);
    }
}

class OwnParamsProbeRecipient implements EmailCommunicable
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
        return 'own_params_probe';
    }

    public function label()
    {
        return 'Own params probe';
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

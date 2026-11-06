<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Mail;

/**
 * The two degenerate group sizes — one member and none — which are where a grouping seam usually
 * breaks without anyone noticing.
 */
class EmailGroupEdgeCasesTest extends TestCase
{
    /** @return array<int, array<int, string>> one entry per message, holding that message's To: */
    protected function sentMessages(): array
    {
        return collect(Mail::getSymfonyTransport()->messages())
            ->map(fn ($sent) => collect($sent->getOriginalMessage()->getTo())
                ->map(fn ($address) => $address->getAddress())
                ->sort()
                ->values()
                ->all())
            ->values()
            ->all();
    }

    protected function dispatch(array $communicables)
    {
        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Edge probe']);
        $template->content = json_encode(['en' => '<p>Edge probe</p>']);

        return CommunicationType::EMAIL->handler($template)->notify(collect($communicables), []);
    }

    public function test_a_group_of_one_produces_exactly_what_a_plain_recipient_produces()
    {
        $this->dispatch([new EdgeGroupRecipient(['solo@example.test'])]);
        $viaGroup = $this->sentMessages();

        Mail::getSymfonyTransport()->flush();

        $this->dispatch([new EdgeSingleRecipient('solo@example.test')]);
        $viaPlain = $this->sentMessages();

        $this->assertSame([['solo@example.test']], $viaGroup);
        $this->assertSame($viaPlain, $viaGroup, 'a one-address group must be wire-identical to a plain recipient');
    }

    public function test_an_empty_group_is_skipped_rather_than_sent_to_nobody()
    {
        $report = $this->dispatch([new EdgeGroupRecipient([])]);

        $this->assertSame([], $this->sentMessages(), 'an empty group must not put a message on the transport');
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('no-email-address', $report->outcomes()[0]['error']);
    }

    public function test_an_empty_group_does_not_suppress_the_other_recipients()
    {
        $report = $this->dispatch([
            new EdgeGroupRecipient([]),
            new EdgeGroupRecipient(['a@example.test', 'b@example.test']),
            new EdgeSingleRecipient('solo@example.test'),
        ]);

        $this->assertSame(
            [['a@example.test', 'b@example.test'], ['solo@example.test']],
            $this->sentMessages(),
        );

        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame(2, $report->countOf(CommunicationSendingRecipientStatus::SENT));
    }

    public function test_a_group_of_blank_strings_is_treated_as_empty()
    {
        $report = $this->dispatch([new EdgeGroupRecipient(['   ', ''])]);

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
    }
}

class EdgeSingleRecipient implements EmailCommunicable
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
        return 'edge_single';
    }

    public function label()
    {
        return 'Edge single';
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

class EdgeGroupRecipient extends EdgeSingleRecipient
{
    /** @param string[] $emails */
    public function __construct(private array $emails)
    {
        parent::__construct(implode(', ', $emails));
    }

    public function getEmailGroup(): array
    {
        return $this->emails;
    }

    public function getContextKey()
    {
        return 'edge_group';
    }
}

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
 *
 * These live beside EmailGroupSeamTest rather than inside it because they pin the BOUNDARIES of the
 * seam rather than the seam itself: the sizes at which "a group" stops resembling the three-address
 * case every other test uses, and at which an off-by-one in the address handling stops being
 * visible in the To: header.
 *
 * The To: header is read off the array transport, not off a faked Mailable, so the assertions are
 * about what would leave the building rather than about what the caller asked Mail for.
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

    /**
     * A GROUP OF ONE IS INDISTINGUISHABLE FROM A PLAIN RECIPIENT ON THE WIRE.
     *
     * This is the case a host actually hits first and hits constantly: most organisations start with
     * a single address, so if the group path mangled the one-member case — a stray comma, a To:
     * built from the joined string rather than the list — it would break the majority of real sends
     * while every fixture using three addresses stayed green. Asserted against the plain recipient's
     * own output rather than against a literal, so the two paths are held together by construction.
     */
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

    /**
     * AN EMPTY GROUP MUST NOT PRODUCE AN ADDRESSLESS MESSAGE.
     *
     * Mail::to([]) does not throw — it builds a message with an empty To:, which most transports
     * accept and then either bounce or, worse, deliver to whatever the envelope defaults to. The
     * organisation is reported as sent to and nobody receives anything, so the failure surfaces as a
     * complaint weeks later rather than as an error. The recipient is skipped instead, carrying the
     * SAME reason string the single-address path uses so nothing downstream needs a new case.
     */
    public function test_an_empty_group_is_skipped_rather_than_sent_to_nobody()
    {
        $report = $this->dispatch([new EdgeGroupRecipient([])]);

        $this->assertSame([], $this->sentMessages(), 'an empty group must not put a message on the transport');
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('no-email-address', $report->outcomes()[0]['error']);
    }

    /**
     * An empty group does not take the rest of the send down with it: the position is skipped and
     * every other recipient still receives. Stated separately because the all-or-nothing failure
     * mode reappears at the SEND level even when it has been ruled out at the address level.
     */
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

    /**
     * A group whose members are all whitespace is the empty case in disguise.
     *
     * Worth its own assertion because the address list is free text in at least one host, so "  "
     * is a value that actually occurs, and a seam that only checked count() would send a message
     * addressed to a blank string.
     */
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

<?php

namespace Condoedge\Communications\Tests\Feature\Engagement;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationSendingRecipient;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\Engagement\EngagementRecorder;
use Condoedge\Communications\Tests\TestCase;

/**
 * What a provider reports about one recipient row.
 */
class EngagementRecorderTest extends TestCase
{
    protected function recorder(): EngagementRecorder
    {
        return app(EngagementRecorder::class);
    }

    protected function recipientRow(CommunicationSendingRecipientStatus $status): CommunicationSendingRecipient
    {
        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->subject = json_encode(['en' => 'Engagement probe']);
        $template->content = json_encode(['en' => '<p>Engagement probe</p>']);
        $template->save();

        $row = CommunicationSending::createOneForCommunicationTemplate(
            $template,
            collect([new EngagementProbeRecipient('one@example.test')]),
        )->recipients()->first();

        $row->status = $status;
        $row->save();

        return $row;
    }

    public function test_an_open_sets_the_status_and_the_time_once(): void
    {
        $row = $this->recipientRow(CommunicationSendingRecipientStatus::SENT);
        $first = now()->subHour();

        $this->recorder()->record($row->id, CommunicationSendingRecipientStatus::OPENED, $first, 'open');
        $this->recorder()->record($row->id, CommunicationSendingRecipientStatus::OPENED, now(), 'open');

        $row->refresh();
        $this->assertSame(CommunicationSendingRecipientStatus::OPENED, $row->status);
        $this->assertEquals($first->toDateTimeString(), (string) $row->opened_at);
        $this->assertSame('open', $row->provider_event);
    }

    public function test_a_late_delivery_report_does_not_undo_a_click(): void
    {
        $row = $this->recipientRow(CommunicationSendingRecipientStatus::CLICKED);

        $this->recorder()->record($row->id, CommunicationSendingRecipientStatus::DELIVERED, now(), 'delivered');

        $row->refresh();
        $this->assertSame(CommunicationSendingRecipientStatus::CLICKED, $row->status);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_a_bounce_replaces_sent_but_not_opened(): void
    {
        $sent = $this->recipientRow(CommunicationSendingRecipientStatus::SENT);
        $opened = $this->recipientRow(CommunicationSendingRecipientStatus::OPENED);

        $this->recorder()->record($sent->id, CommunicationSendingRecipientStatus::BOUNCED, now(), 'bounce');
        $this->recorder()->record($opened->id, CommunicationSendingRecipientStatus::BOUNCED, now(), 'bounce');

        $this->assertSame(CommunicationSendingRecipientStatus::BOUNCED, $sent->refresh()->status);
        $this->assertSame(CommunicationSendingRecipientStatus::OPENED, $opened->refresh()->status);
    }

    public function test_the_provider_message_id_is_kept_when_a_later_report_has_none(): void
    {
        $row = $this->recipientRow(CommunicationSendingRecipientStatus::SENT);

        $this->recorder()->record($row->id, CommunicationSendingRecipientStatus::DELIVERED, now(), 'delivered', 'msg-1');
        $this->recorder()->record($row->id, CommunicationSendingRecipientStatus::OPENED, now(), 'open');

        $this->assertSame('msg-1', $row->refresh()->provider_message_id);
    }

    public function test_an_unknown_row_is_ignored(): void
    {
        $this->assertFalse($this->recorder()->record(0, CommunicationSendingRecipientStatus::OPENED));
    }

    public function test_a_status_no_provider_reports_is_ignored(): void
    {
        $row = $this->recipientRow(CommunicationSendingRecipientStatus::SENT);

        $this->assertFalse($this->recorder()->record($row->id, CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame(CommunicationSendingRecipientStatus::SENT, $row->refresh()->status);
    }
}

class EngagementProbeRecipient implements EmailCommunicable
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
        return 'engagement_probe';
    }

    public function label()
    {
        return 'Engagement probe';
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

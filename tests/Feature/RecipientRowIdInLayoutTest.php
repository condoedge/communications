<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Mail;

/**
 * Each recipient's email layout knows the id of that recipient's row in the send log.
 */
class RecipientRowIdInLayoutTest extends TestCase
{
    protected function template(): CommunicationTemplate
    {
        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->subject = json_encode(['en' => 'Row probe']);
        $template->content = json_encode(['en' => '<p>Row probe</p>']);
        $template->save();

        return $template;
    }

    protected function rowIdsInSentLayouts(): array
    {
        return Mail::sent(DefaultLayoutEmailCommunicable::class)
            ->map(fn ($mailable) => $mailable->params['communication_sending_recipient_id'] ?? null)
            ->all();
    }

    public function test_each_layout_receives_the_row_id_of_its_recipient(): void
    {
        Mail::fake();

        $sending = $this->template()->notify(collect([
            new RowProbeRecipient('a@example.test'),
            new RowProbeRecipient('b@example.test'),
        ]));

        $rowIds = $sending->recipients()->orderBy('position')->pluck('id')->all();

        $this->assertCount(2, $rowIds);
        $this->assertSame($rowIds, $this->rowIdsInSentLayouts());
    }

    public function test_a_skipped_recipient_does_not_shift_the_ids(): void
    {
        Mail::fake();

        $sending = $this->template()->notify(collect([
            new RowProbeRecipient('not-an-address'),
            new RowProbeRecipient('b@example.test'),
        ]));

        $this->assertSame(
            [$sending->recipients()->where('email', 'b@example.test')->value('id')],
            $this->rowIdsInSentLayouts(),
        );
    }

    public function test_a_handler_used_without_a_sending_adds_no_id(): void
    {
        Mail::fake();

        CommunicationType::EMAIL->handler($this->template())
            ->notify(collect([new RowProbeRecipient('a@example.test')]), []);

        Mail::assertSent(
            DefaultLayoutEmailCommunicable::class,
            fn ($mailable) => !array_key_exists('communication_sending_recipient_id', $mailable->params),
        );
    }
}

class RowProbeRecipient implements EmailCommunicable
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
        return 'row_probe';
    }

    public function label()
    {
        return 'Row probe';
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

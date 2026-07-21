<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Facades\ContentReplacer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Condoedge\Utils\Models\Model;

class CommunicationTemplate extends Model
{
    use \Kompo\Database\HasTranslations;

    protected $casts = [
        "type" => CommunicationType::class,
    ];

    protected $translatable = [
        'subject',
        'content',
    ];

    public function save(array $options = [])
    {
        $this->is_draft = $this->is_draft ?? ($this->getHandler()->isDraft() ? 1 : 0);

        return parent::save($options);
    }

    // RELATIONSHIPS
    public function group()
    {
        return $this->belongsTo(CommunicationTemplateGroup::class, 'template_group_id');
    }

    public function communicationSendings()
    {
        return $this->hasMany(CommunicationSending::class);
    }

    // CALCULATED FIELDS
    public function getHandler()
    {
        return $this->type->handler($this);
    }

    public function getParsedTitle($params = [])
    {
        return ContentReplacer::setText($this->subject ?? '')
            ->injectContext($params)
            ->replace($this->type);
    }

    public function getParsedContent($params = [])
    {
        return ContentReplacer::setText($this->content ?? '')
            ->injectContext($params)
            ->replace($this->type);
    }

    // SCOPES
    public function scopeIsValid($query)
    {
        return $query->where('is_draft', 0);
    }
    
    // ACTIONS
    /**
     * Send this channel and record what actually happened per recipient.
     *
     * Bookkeeping is deliberately OUTSIDE the try: if the rows cannot be written then nothing has
     * been delivered, so letting it propagate is safe and lets the queue retry. Once delivery has
     * begun the opposite holds — the recipients already reached cannot be un-sent, so a handler
     * failure is recorded and logged rather than rethrown into a duplicate-producing retry.
     */
    public function notify(array|Collection $communicables, $params = []): ?CommunicationSending
    {
        // Fix the order once so the positions in the recipient rows line up with the positions the
        // handler reports outcomes against.
        $communicables = collect($communicables)->values();

        if ($communicables->isEmpty()) {
            return null;
        }

        $communicationSending = CommunicationSending::createOneForCommunicationTemplate($this, $communicables, $params);

        $report = null;

        try {
            $report = $this->getHandler()->notify($communicables, $params);
        } catch (\Throwable $e) {
            Log::error('Error sending communication: ' . $e->getMessage(), [
                'communication_id' => $this->id,
                'channel' => $this->type?->value,
                'exception' => $e,
            ]);

            $communicationSending->error_message = mb_substr($e->getMessage(), 0, 1000);
        }

        // Recording the outcome is separate from producing it. Delivery has already happened by this
        // point, so a failure to write it down must never propagate — that would fail the job and
        // send the whole channel a second time on retry.
        try {
            $report
                ? $communicationSending->applyDeliveryReport($report)
                : $communicationSending->markAllRecipientsFailed($communicationSending->error_message);

            $communicationSending->save();
        } catch (\Throwable $e) {
            Log::error('Failed to record communication delivery outcome', [
                'communication_sending_id' => $communicationSending->id,
                'exception' => $e,
            ]);
        }

        return $communicationSending;
    }

    public function delete()
    {
        if ($this->communicationSendings()->count()) {
            abort(403, 'error.cannot-delete-a-communication-with-sendings');
        }

        NotificationTemplate::where('communication_id', $this->id)->delete();

        return parent::delete();
    }
}
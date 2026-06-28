<?php

namespace Condoedge\Communications\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Condoedge\Utils\Models\Model;

class CommunicationSending extends Model
{
    protected $casts = [
        'status' => CommunicationSendingStatus::class,
        'sent_at' => 'datetime',
    ];

    // RELATIONSHIPS
    public function communicationTemplate()
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }

    public function recipients()
    {
        return $this->hasMany(CommunicationSendingRecipient::class, 'communication_sending_id');
    }

    // ACTIONS
    public static function createOneForCommunicationTemplate(CommunicationTemplate $communicationTemplate, array|Collection $communicables, array $params = [])
    {
        $communicables = collect($communicables)->values();
        $paramsTeamId = $params['team_id'] ?? null;

        $communicationSending = new static;
        $communicationSending->communication_template_id = $communicationTemplate->id;
        $communicationSending->status = CommunicationSendingStatus::PENDING;
        $communicationSending->team_id = $paramsTeamId;
        $communicationSending->trigger = $communicationTemplate->group?->trigger;
        $communicationSending->channel = $communicationTemplate->type?->value;
        $communicationSending->recipients_count = $communicables->count();
        $communicationSending->save();

        $communicationSending->writeRecipientRows($communicables, $paramsTeamId);

        return $communicationSending;
    }

    /**
     * One communication_sending_recipients row per communicable.
     */
    protected function writeRecipientRows(Collection $communicables, $paramsTeamId): void
    {
        if (!Schema::hasTable('communication_sending_recipients')) {
            return;
        }

        foreach ($communicables as $communicable) {
            $identity = static::unwrapRecipient($communicable);

            $row = new CommunicationSendingRecipient;
            $row->communication_sending_id = $this->id;
            $row->status = CommunicationSendingRecipientStatus::PENDING;
            $row->name = static::safeName($communicable);
            $row->email = static::safeEmail($communicable);
            $row->team_id = $paramsTeamId ?? (is_object($identity) ? ($identity->team_id ?? null) : null);

            if ($identity instanceof EloquentModel) {
                $row->recipient()->associate($identity);
            }

            $row->save();
        }
    }

    public function markRecipientsSent(): void
    {
        if (!Schema::hasTable('communication_sending_recipients')) {
            return;
        }

        $this->recipients()->update([
            'status' => CommunicationSendingRecipientStatus::SENT->value,
            'sent_at' => now(),
        ]);
    }

    public function markRecipientsFailed(): void
    {
        if (!Schema::hasTable('communication_sending_recipients')) {
            return;
        }

        $this->recipients()->update([
            'status' => CommunicationSendingRecipientStatus::FAILED->value,
        ]);
    }

    // HELPERS
    protected static function unwrapRecipient($communicable)
    {
        // RecipientOverride wraps the real recipient; unwrap it so the morph points at the underlying model, not the send-time decorator.
        if ($communicable instanceof \Condoedge\Communications\Recipients\RecipientOverride) {
            return $communicable->getInner();
        }

        return $communicable;
    }

    protected static function safeEmail($communicable): ?string
    {
        try {
            if (is_object($communicable) && method_exists($communicable, 'getEmail')) {
                return $communicable->getEmail();
            }
        } catch (\Throwable $e) {
            // tolerate communicables whose getEmail() throws (recorded as a null-email row).
        }

        return null;
    }

    protected static function safeName($communicable): ?string
    {
        // label() is the abstract Communicable display name — keeps CRM (Person) out of the log.
        try {
            if (is_object($communicable) && method_exists($communicable, 'label')) {
                $label = $communicable->label();

                return $label !== null ? (string) $label : null;
            }
        } catch (\Throwable $e) {
            // tolerate communicables whose label() throws (recorded as a null-name row).
        }

        return null;
    }

    // ELEMENTS
    public function statusPill()
    {
        return _Pill($this->status->label())->class($this->status->classes())->class('text-white');
    }
}

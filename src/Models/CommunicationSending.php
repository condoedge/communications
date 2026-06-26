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

        // New send-log header fields (guarded so the writer keeps working before the A1 columns land).
        if (Schema::hasColumn('communication_sendings', 'team_id')) {
            $communicationSending->team_id = $paramsTeamId;
        }
        if (Schema::hasColumn('communication_sendings', 'trigger')) {
            $communicationSending->trigger = $communicationTemplate->group?->trigger;
        }
        if (Schema::hasColumn('communication_sendings', 'channel')) {
            $communicationSending->channel = $communicationTemplate->type?->value;
        }
        if (Schema::hasColumn('communication_sendings', 'recipients_count')) {
            $communicationSending->recipients_count = $communicables->count();
        }

        $communicationSending->save();

        $communicationSending->writeRecipientRows($communicables, $paramsTeamId);

        return $communicationSending;
    }

    /**
     * One communication_sending_recipients row per communicable. Tolerant of mixed types:
     * unwraps RecipientOverride-style wrappers for identity, guards getEmail(), and only sets
     * person_id / morph when the underlying recipient is an Eloquent model.
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
            $row->email = static::safeEmail($communicable);
            $row->team_id = $paramsTeamId ?? (is_object($identity) ? ($identity->team_id ?? null) : null);

            if ($identity instanceof EloquentModel) {
                $row->recipient()->associate($identity);

                if (static::isPerson($identity)) {
                    $row->person_id = $identity->getKey();
                }
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
        // RecipientOverride wraps the real recipient; unwrap it so person_id / the
        // morph point at the underlying model, not the send-time decorator.
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

    protected static function isPerson($identity): bool
    {
        if (!class_exists(\Condoedge\Crm\Facades\PersonModel::class)) {
            return false;
        }

        $personClass = \Condoedge\Crm\Facades\PersonModel::getClass();

        return $identity instanceof $personClass;
    }

    // ELEMENTS
    public function statusPill()
    {
        return _Pill($this->status->label())->class($this->status->classes())->class('text-white');
    }
}

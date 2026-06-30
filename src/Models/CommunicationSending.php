<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\HasCommunicationTeam;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
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
        $communicationTeams = $params['communication_teams'] ?? [];

        $communicationSending = new static;
        $communicationSending->communication_template_id = $communicationTemplate->id;
        $communicationSending->status = CommunicationSendingStatus::PENDING;
        $communicationSending->team_id = $paramsTeamId;
        $communicationSending->trigger = $communicationTemplate->group?->trigger;
        $communicationSending->channel = $communicationTemplate->type?->value;
        $communicationSending->recipients_count = $communicables->count();
        $communicationSending->save();

        $communicationSending->writeRecipientRows($communicables, $communicationTeams, $paramsTeamId);

        return $communicationSending;
    }

    /**
     * One recipient row per communicable, plus a team pivot row per team the send is recorded against
     * (the recipient's own declared teams when it implements HasCommunicationTeam, otherwise the
     * communication's teams). A send relevant to several teams is therefore counted once but appears
     * in each of them.
     *
     * @param int[] $communicationTeams
     */
    protected function writeRecipientRows(Collection $communicables, array $communicationTeams, ?int $templateTeamId): void
    {
        foreach ($communicables as $communicable) {
            $identity = static::unwrapRecipient($communicable);

            $row = new CommunicationSendingRecipient;
            $row->communication_sending_id = $this->id;
            $row->status = CommunicationSendingRecipientStatus::PENDING;
            $row->name = secureCall(fn () => (string) $communicable->label()) ?: null;
            $row->email = secureCall(fn () => $communicable instanceof EmailCommunicable ? $communicable->getEmail() : null);

            if ($identity instanceof EloquentModel) {
                $row->recipient()->associate($identity);
            }

            $row->save();

            $row->recordTeams($this->teamsForRecipient($identity, $communicationTeams, $templateTeamId));
        }
    }

    public function markRecipientsSent(): void
    {
        $this->recipients()->update([
            'status' => CommunicationSendingRecipientStatus::SENT->value,
            'sent_at' => now(),
        ]);
    }

    public function markRecipientsFailed(): void
    {
        $this->recipients()->update([
            'status' => CommunicationSendingRecipientStatus::FAILED->value,
        ]);
    }

    // HELPERS

    /**
     * The teams one recipient is recorded against: its own declaration wins (the "each recipient in a
     * different team" case), else the communication's teams, else the sending's template team.
     *
     * @param int[] $communicationTeams
     * @return int[]
     */
    protected function teamsForRecipient($identity, array $communicationTeams, ?int $templateTeamId): array
    {
        $teams = $identity instanceof HasCommunicationTeam ? $identity->getCommunicationTeamIds() : $communicationTeams;

        if (empty($teams)) {
            $teams = array_filter([$templateTeamId]);
        }

        return collect($teams)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    protected static function unwrapRecipient($communicable)
    {
        // RecipientOverride wraps the real recipient; unwrap it so the morph points at the underlying model, not the send-time decorator.
        if ($communicable instanceof \Condoedge\Communications\Recipients\RecipientOverride) {
            return $communicable->getInner();
        }

        return $communicable;
    }

    // ELEMENTS
    public function statusPill()
    {
        return _Pill($this->status->label())->class($this->status->classes())->class('text-white');
    }
}

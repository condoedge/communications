<?php

namespace Condoedge\Communications\Models;

use Condoedge\Utils\Models\Model;
use Illuminate\Support\Facades\DB;

class CommunicationSendingRecipient extends Model
{
    protected const TEAMS_PIVOT = 'communication_sending_recipient_teams';

    protected $casts = [
        'status' => CommunicationSendingRecipientStatus::class,
    ];

    // RELATIONSHIPS
    public function communicationSending()
    {
        return $this->belongsTo(CommunicationSending::class);
    }

    public function recipient()
    {
        return $this->morphTo();
    }

    // ACTIONS

    /**
     * Record the teams this recipient appears under (one pivot row per team). A send relevant to
     * several teams is counted once here but shows in each team's per-team views.
     *
     * @param int[] $teamIds
     */
    public function recordTeams(array $teamIds): void
    {
        if (empty($teamIds)) {
            return;
        }

        DB::table(self::TEAMS_PIVOT)->insert(
            collect($teamIds)->map(fn ($teamId) => [
                'communication_sending_recipient_id' => $this->id,
                'team_id' => (int) $teamId,
            ])->all()
        );
    }
}

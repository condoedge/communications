<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Recipients\RecipientKey;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\HasCommunicationTeam;
use Condoedge\Communications\Services\CommunicationHandlers\DeliveryReport;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Recipient row ids from this instance's write, keyed by the communicable's position in the
     * collection. Position rather than identity: the same person can legitimately appear twice
     * (two audiences unioned), and those entries must keep separate outcomes.
     *
     * @var array<int, int>
     */
    protected array $recipientRowIds = [];

    // ACTIONS
    public static function createOneForCommunicationTemplate(CommunicationTemplate $communicationTemplate, array|Collection $communicables, array $params = [])
    {
        $communicables = collect($communicables)->values();
        $paramsTeamId = $params['team_id'] ?? null;
        $communicationTeams = $params['communication_teams'] ?? [];

        // The sending and its recipient rows are one unit: a half-written set would leave rows that
        // no delivery outcome can ever resolve, stranding the send as PENDING forever.
        return DB::transaction(function () use ($communicationTemplate, $communicables, $params, $paramsTeamId, $communicationTeams) {
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
        });
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
        foreach ($communicables as $position => $communicable) {
            $identity = static::unwrapRecipient($communicable);

            $row = new CommunicationSendingRecipient;
            $row->communication_sending_id = $this->id;
            $row->status = CommunicationSendingRecipientStatus::PENDING;
            $row->name = secureCallCb(fn () => (string) $communicable->label()) ?: null;
            // Maybe it should be more abstract and just call a generic method that fills a "contact_info" field. This is something pending to change
            $row->email = secureCallCb(fn () => $communicable instanceof EmailCommunicable ? $communicable->getEmail() : null);

            if ($identity instanceof EloquentModel) {
                $row->recipient()->associate($identity);
            }

            $row->save();

            $this->recipientRowIds[$position] = $row->id;

            $row->recordTeams($this->teamsForRecipient($identity, $communicationTeams, $templateTeamId));
        }
    }

    /**
     * Stamp each recipient row with what actually happened to it, then derive the sending's own
     * status from those outcomes. A send where some recipients got through and others did not is
     * PARTIAL — reporting it as either SENT or FAILED misleads in both directions.
     */
    public function applyDeliveryReport(DeliveryReport $report): void
    {
        if ($report->isEmpty()) {
            // The handler reported nothing at all. Leave the rows PENDING rather than inventing an
            // outcome — an unresolved send is a bug worth seeing, not one worth hiding as SENT.
            return;
        }

        $sentAt = now();

        // Grouped so a 5000-recipient send is a couple of UPDATEs instead of 5000.
        foreach ($this->groupRowIdsByOutcome($report) as $group) {
            $this->recipients()->whereIn('id', $group['ids'])->update([
                'status' => $group['status']->value,
                'sent_at' => $group['status'] === CommunicationSendingRecipientStatus::SENT ? $sentAt : null,
                'error_message' => $group['error'],
            ]);
        }

        $sent = $report->countOf(CommunicationSendingRecipientStatus::SENT);
        $failed = $report->countOf(CommunicationSendingRecipientStatus::FAILED);

        $this->status = match (true) {
            $sent > 0 && $failed > 0 => CommunicationSendingStatus::PARTIAL,
            $sent > 0 => CommunicationSendingStatus::SENT,
            $failed > 0 => CommunicationSendingStatus::FAILED,
            // Nobody was reachable on this channel: no message went out, but nothing broke either.
            default => CommunicationSendingStatus::SKIPPED,
        };

        $this->sent_at = $sent > 0 ? $sentAt : null;
    }

    /**
     * Collapse per-recipient outcomes into one bucket per (status, error) pair.
     *
     * @return array<array{status: CommunicationSendingRecipientStatus, error: ?string, ids: int[]}>
     */
    protected function groupRowIdsByOutcome(DeliveryReport $report): array
    {
        $groups = [];

        foreach ($report->outcomes() as $position => $outcome) {
            $rowId = $this->recipientRowIds[$position] ?? null;

            if (!$rowId) {
                continue;
            }

            $bucket = $outcome['status']->value . '|' . $outcome['error'];

            $groups[$bucket] ??= ['status' => $outcome['status'], 'error' => $outcome['error'], 'ids' => []];
            $groups[$bucket]['ids'][] = $rowId;
        }

        return array_values($groups);
    }

    /**
     * The send died before any per-recipient outcome could be reported (the handler threw as a
     * whole, or bookkeeping failed). Nothing is known to have been delivered.
     */
    public function markAllRecipientsFailed(?string $error = null): void
    {
        $this->recipients()->update([
            'status' => CommunicationSendingRecipientStatus::FAILED->value,
            // Cleared explicitly: an earlier partial apply may already have stamped some rows, and
            // the stats count sent_at rather than status, so a leftover timestamp reads as delivered.
            'sent_at' => null,
            'error_message' => $error === null ? null : mb_substr($error, 0, 1000),
        ]);

        $this->status = CommunicationSendingStatus::FAILED;
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
        // Each source is tried in turn and only a non-empty result wins. A recipient that implements
        // HasCommunicationTeam but declares no team must still fall back to the communication's
        // teams, otherwise it lands with no pivot row and disappears from every log and stat.
        $own = $identity instanceof HasCommunicationTeam ? $identity->getCommunicationTeamIds() : [];

        $teams = $this->normalizeTeamIds($own)
            ?: ($this->normalizeTeamIds($communicationTeams)
                ?: $this->normalizeTeamIds([$templateTeamId]));

        if (!$teams) {
            Log::warning('Communication recipient recorded with no team attribution', [
                'communication_sending_id' => $this->id,
                'recipient' => $identity instanceof EloquentModel ? get_class($identity) . ':' . $identity->getKey() : null,
            ]);
        }

        return $teams;
    }

    /** @return int[] */
    protected function normalizeTeamIds(array $teamIds): array
    {
        return collect($teamIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    protected static function unwrapRecipient($communicable)
    {
        // RecipientOverride wraps the real recipient; unwrap it so the morph points at the underlying model, not the send-time decorator.
        return RecipientKey::unwrap($communicable);
    }

    // ELEMENTS
    public function statusPill()
    {
        return _Pill($this->status->label())->class($this->status->classes())->class('text-white');
    }
}

<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationSendingRecipient;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Utils\Kompo\Common\WhiteTable;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

/**
 * Per-recipient send log over the team subtree. One row per communication_sending_recipients row
 * (joined to its sending for the denormalized trigger + channel). Scoped to the viewing team and
 * every team beneath it; filtered by status / free text; each row drills into its full send.
 *
 * `compact` mode (used by the Overview tab) drops the filter bar + export and shows a short recent
 * slice so the same query is the single source of truth for "recent sends".
 */
class CommunicationSendLogList extends WhiteTable
{
    public $id = 'communication-send-log-table';

    protected $teamId;
    protected array $subtreeIds = [];
    protected bool $compact = false;
    protected $isExport;

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
        $this->compact = (bool) $this->prop('compact');
        $this->isExport = $this->prop('is_export');

        $this->subtreeIds = collect(app(TeamHierarchyInterface::class)->getDescendantTeamIds((int) $this->teamId))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->perPage = $this->compact ? 8 : 30;
    }

    public function top()
    {
        if ($this->compact || $this->isExport) {
            return null;
        }

        return _FlexEnd(
            _Select()->options(CommunicationSendingRecipientStatus::optionsWithLabels())
                ->placeholder('communications.filter-by-status')
                ->name('status', false)
                ->class('!mb-0 max-w-xs')
                ->serverFilter(),
            _Input()->placeholder('communications.search-recipients')
                ->name('search', false)
                ->class('!mb-0 max-w-xs')
                ->serverFilter(),
            _ExcelExportButton()->class('!mb-0'),
        )->class('mb-4 gap-3');
    }

    public function headers()
    {
        return [
            _Th('communications.sent-at'),
            _Th('communications.recipient'),
            _Th('communications.trigger'),
            _Th('communications.channel'),
            _Th('communications.status'),
        ];
    }

    public function query()
    {
        return CommunicationSendingRecipient::query()
            ->join('communication_sendings', 'communication_sendings.id', '=', 'communication_sending_recipients.communication_sending_id')
            ->whereNull('communication_sending_recipients.deleted_at')
            ->whereIn('communication_sending_recipients.team_id', $this->subtreeIds ?: [-1])
            ->with('person')
            ->when(request('status'), fn ($q, $status) => $q->where('communication_sending_recipients.status', $status))
            ->when(request('search'), fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('communication_sending_recipients.email', 'like', '%' . $search . '%')
                ->orWhereHas('person', fn ($pq) => $pq->search($search))))
            ->orderByDesc('communication_sending_recipients.id')
            ->select(
                'communication_sending_recipients.*',
                'communication_sendings.trigger as sending_trigger',
                'communication_sendings.channel as sending_channel',
            );
    }

    public function render($recipient)
    {
        $row = _TableRow(
            _Html(communicationDateTime($recipient->sent_at))->class('text-sm'),
            _Rows(
                _Html($recipient->person?->full_name ?: '—')->class('font-medium'),
                _Html($recipient->email ?: '—')->class('text-sm text-gray-500'),
            ),
            _Html(CommunicationTemplateGroup::triggerName($recipient->sending_trigger)),
            _Html(CommunicationType::labelFor($recipient->sending_channel)),
            $this->statusPill($recipient->status),
        );

        if ($this->isExport) {
            return $row;
        }

        return $row->selfGet('showSend', ['id' => $recipient->communication_sending_id])->inModal();
    }

    public function showSend($id)
    {
        return new CommunicationSendingModal($id);
    }

    public function getExportableInstance()
    {
        return new static([
            'team_id' => $this->teamId,
            'is_export' => true,
        ]);
    }

    protected function statusPill(?CommunicationSendingRecipientStatus $status)
    {
        return $status ? $status->statusPill() : _Html('—')->class('text-gray-400');
    }
}

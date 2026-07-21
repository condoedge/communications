<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract;
use Condoedge\Communications\Services\Stats\Dto\TeamStatsDto;
use Condoedge\Utils\Kompo\Common\WhiteTable;
use Illuminate\Support\Collection;
use Kompo\Auth\Facades\TeamModel;

/**
 * Per-team rollup of the send log over the subtree (sent / failed), one row per owning team.
 * Team names are preloaded once to keep render() free of per-row lookups.
 */
class CommunicationByTeamList extends WhiteTable
{
    public $id = 'communication-by-team-table';

    protected $teamId;
    protected Collection $rows;
    protected Collection $teamNames;

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
        $this->rows = app(CommunicationStatsServiceContract::class)->perTeam([(int) $this->teamId]);

        $ids = $this->rows->pluck('teamId')->filter()->unique();
        $this->teamNames = TeamModel::asSystemOperation()->whereIn('id', $ids)->pluck('team_name', 'id');
    }

    public function query()
    {
        return $this->rows;
    }

    public function headers()
    {
        return [
            _Th('communications.team'),
            _Th('communications.sent'),
            _Th('communications.failed'),
        ];
    }

    public function render(TeamStatsDto $row)
    {
        return _TableRow(
            _Html($this->teamLabel($row->teamId))->class('font-medium'),
            _Html((string) $row->sent),
            _Html((string) $row->failed)->class($row->failed > 0 ? 'text-danger' : ''),
        );
    }

    protected function teamLabel(?int $teamId): string
    {
        if ($teamId === null) {
            return __('communications.unknown-team');
        }

        return $this->teamNames[$teamId] ?? ('#' . $teamId);
    }
}

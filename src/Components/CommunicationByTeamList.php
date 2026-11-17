<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract;
use Condoedge\Communications\Services\Stats\Dto\TeamStatsDto;
use Condoedge\Utils\Kompo\Common\WhiteTable;
use Illuminate\Support\Collection;
use Kompo\Auth\Facades\TeamModel;

/**
 * Per-team rollup of the send log over the subtree, one row per owning team.
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

        $this->teamNames = TeamModel::asSystemOperation()->whereIn('id', $ids)->get()->pluck('team_name', 'id');
    }

    public function query()
    {
        return $this->rows;
    }

    public function headers()
    {
        return array_merge([
            _Th('communications.team'),
            _Th('communications.sent'),
            _Th('communications.failed'),
        ], $this->showsEngagement() ? [
            _Th('communications.delivered'),
            _Th('communications.opened'),
            _Th('communications.clicked'),
            _Th('communications.bounced'),
        ] : []);
    }

    public function render(TeamStatsDto $row)
    {
        return _TableRow(array_merge([
            _Html($this->teamLabel($row->teamId))->class('font-medium'),
            _Html((string) $row->sent),
            _Html((string) $row->failed)->class($row->failed > 0 ? 'text-danger' : ''),
        ], $this->showsEngagement() ? [
            _Html((string) $row->delivered),
            _Html((string) $row->opened),
            _Html((string) $row->clicked),
            _Html((string) $row->bounced)->class($row->bounced > 0 ? 'text-danger' : ''),
        ] : []));
    }

    protected function showsEngagement(): bool
    {
        return (bool) config('kompo-communications.engagement-stats');
    }

    protected function teamLabel(?int $teamId): string
    {
        if ($teamId === null) {
            return __('communications.unknown-team');
        }

        return $this->teamNames[$teamId] ?? ('#' . $teamId);
    }
}

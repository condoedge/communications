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

        // ->get()->pluck(), NOT ->pluck() on the builder. Builder::pluck() opens with
        // `$this->toBase()->pluck($column, $key)` and only consults hasAnyGetMutator afterwards, so
        // the column name goes into the SELECT literally and an accessor can never rescue it. A host
        // whose teams table does not have a physical `team_name` column then gets
        // SQLSTATE[42S22] Unknown column 'team_name' on every boot of this component, with no seam
        // to fix it from — which is exactly what Coolecto hit (its column is `name`).
        //
        // Collection::pluck() reads the ATTRIBUTE, so getTeamNameAttribute() is enough. That makes
        // the model the single naming seam for both reads in this package — this one and
        // CommunicationTemplatesList::inheritedLabel(), which was already hydrated.
        $this->teamNames = TeamModel::asSystemOperation()->whereIn('id', $ids)->get()->pluck('team_name', 'id');
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

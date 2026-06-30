<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract;
use Condoedge\Communications\Services\Stats\Dto\TriggerStatsDto;
use Condoedge\Utils\Kompo\Common\WhiteTable;
use Illuminate\Support\Collection;

/**
 * Per-trigger rollup of the send log over the team subtree (sent / failed / opened / click-rate).
 * Source is the stats service collection, one row per trigger seen in the scoped log.
 */
class CommunicationByTriggerList extends WhiteTable
{
    public $id = 'communication-by-trigger-table';

    protected $teamId;
    protected Collection $rows;

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
        $this->rows = app(CommunicationStatsServiceContract::class)->perTrigger([(int) $this->teamId]);
    }

    public function query()
    {
        return $this->rows;
    }

    public function headers()
    {
        return [
            _Th('communications.trigger'),
            _Th('communications.sent'),
            _Th('communications.failed'),
            _Th('communications.opened'),
            _Th('communications.click-rate'),
        ];
    }

    public function render(TriggerStatsDto $row)
    {
        return _TableRow(
            _Html(CommunicationTemplateGroup::triggerName($row->trigger))->class('font-medium'),
            _Html((string) $row->sent),
            _Html((string) $row->failed)->class($row->failed > 0 ? 'text-danger' : ''),
            _Html((string) $row->opened),
            _Html($row->clickRate . '%'),
        );
    }
}

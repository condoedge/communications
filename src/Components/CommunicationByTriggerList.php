<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract;
use Condoedge\Communications\Services\Stats\Dto\TriggerStatsDto;
use Condoedge\Utils\Kompo\Common\WhiteTable;
use Illuminate\Support\Collection;

/**
 * Per-trigger rollup of the send log over the team subtree, one row per trigger seen in the log.
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
        return array_merge([
            _Th('communications.trigger'),
            _Th('communications.sent'),
            _Th('communications.failed'),
        ], $this->showsEngagement() ? [
            _Th('communications.delivered'),
            _Th('communications.opened'),
            _Th('communications.clicked'),
            _Th('communications.bounced'),
        ] : []);
    }

    public function render(TriggerStatsDto $row)
    {
        return _TableRow(array_merge([
            _Html(CommunicationTemplateGroup::triggerName($row->trigger))->class('font-medium'),
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
}

<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract;
use Condoedge\Communications\Services\Stats\Dto\StatsOverviewDto;
use Condoedge\Utils\Kompo\Common\Form;

/**
 * Communications dashboard overview for a team subtree: KPI cards from the stats service plus a
 * compact "recent sends" slice of the same send log.
 */
class CommunicationsOverview extends Form
{
    public $id = 'communications-overview';

    protected $teamId;

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
    }

    public function render()
    {
        return _Rows(
            $this->statCards($this->overviewStats()),
            _TitleMini('communications.recent-sends')->class('mt-6 mb-2'),
            new CommunicationSendLogList([
                'team_id' => $this->teamId,
                'compact' => true,
            ]),
        )->class('gap-2');
    }

    /** Public seam so the KPI snapshot can be asserted without scraping rendered cards. */
    public function overviewStats(): StatsOverviewDto
    {
        return app(CommunicationStatsServiceContract::class)->overview([(int) $this->teamId]);
    }

    protected function statCards(StatsOverviewDto $stats)
    {
        return _Flex(
            _MiniStatCard('communications.total-sent', $stats->totalSent, 'send-2', 'bg-greenmain'),
            _MiniStatCard('communications.failed', $stats->failed, 'danger', 'bg-danger'),
            _MiniStatCard('communications.last-30-days', $stats->last30d, 'calendar', 'bg-level1'),
            _MiniStatCard('communications.active-triggers', $stats->activeTriggers, 'flash', 'bg-positive'),
            _MiniStatCard('communications.disabled-triggers', $stats->disabledTriggers, 'slash', 'bg-warning'),
        )->class('gap-4 flex-wrap');
    }
}

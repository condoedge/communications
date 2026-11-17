<?php

namespace Condoedge\Communications\Services\Stats;

use Condoedge\Communications\Services\Stats\Dto\StatsOverviewDto;
use Illuminate\Support\Collection;

/**
 * Read-side aggregates over the communication send log, scoped to a team subtree.
 */
interface CommunicationStatsServiceContract
{
    /**
     * KPI snapshot for the given anchor teams (expanded to their subtree).
     *
     * @param int[] $teamIds
     */
    public function overview(array $teamIds): StatsOverviewDto;

    /**
     * One TriggerStatsDto per trigger seen in the scoped send log.
     *
     * @param int[] $teamIds
     * @return Collection<int, \Condoedge\Communications\Services\Stats\Dto\TriggerStatsDto>
     */
    public function perTrigger(array $teamIds): Collection;

    /**
     * One TeamStatsDto per owning team in the scoped send log.
     *
     * @param int[] $teamIds
     * @return Collection<int, \Condoedge\Communications\Services\Stats\Dto\TeamStatsDto>
     */
    public function perTeam(array $teamIds): Collection;
}

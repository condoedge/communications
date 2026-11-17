<?php

namespace Condoedge\Communications\Services\Stats\Dto;

/**
 * Top-of-page KPI snapshot for the communications dashboard, scoped to a team subtree.
 */
class StatsOverviewDto
{
    public function __construct(
        public readonly int $totalSent = 0,
        public readonly int $failed = 0,
        public readonly int $last30d = 0,
        public readonly int $activeTriggers = 0,
        public readonly int $disabledTriggers = 0,
        public readonly int $delivered = 0,
        public readonly int $opened = 0,
        public readonly int $clicked = 0,
        public readonly int $bounced = 0,
    ) {
    }
}

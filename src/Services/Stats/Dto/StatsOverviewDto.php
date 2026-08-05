<?php

namespace Condoedge\Communications\Services\Stats\Dto;

/**
 * Top-of-page KPI snapshot for the communications dashboard, scoped to a team subtree.
 *
 * Counts are read from the per-recipient send log (communication_sending_recipients). Engagement
 * (open / click / delivery) is deliberately absent: nothing in this package writes those columns,
 * so exposing a field for them would hand callers a permanent zero dressed up as a measurement.
 */
class StatsOverviewDto
{
    public function __construct(
        public readonly int $totalSent = 0,
        public readonly int $failed = 0,
        public readonly int $last30d = 0,
        public readonly int $activeTriggers = 0,
        public readonly int $disabledTriggers = 0,
    ) {
    }
}

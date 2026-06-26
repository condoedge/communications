<?php

namespace Condoedge\Communications\Services\Stats\Dto;

/**
 * Top-of-page KPI snapshot for the communications dashboard, scoped to a team subtree.
 *
 * Rates are 0–100 floats so the UI can render them verbatim (Kompo renders stat-card values
 * as given). Counts are read from the per-recipient send log (communication_sending_recipients).
 */
class StatsOverviewDto
{
    public function __construct(
        public readonly int $totalSent = 0,
        public readonly int $failed = 0,
        public readonly int $last30d = 0,
        public readonly float $openRate = 0.0,
        public readonly int $activeTriggers = 0,
        public readonly int $disabledTriggers = 0,
    ) {
    }
}

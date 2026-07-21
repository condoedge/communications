<?php

namespace Condoedge\Communications\Services\Stats\Dto;

/**
 * Per-team send-log rollup (one row when breaking the subtree down by owning team).
 */
class TeamStatsDto
{
    public function __construct(
        public readonly ?int $teamId,
        public readonly int $sent = 0,
        public readonly int $failed = 0,
    ) {
    }
}

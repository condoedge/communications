<?php

namespace Condoedge\Communications\Services\Stats\Dto;

/**
 * Per-trigger send-log rollup (one row in the Templates/Stats table).
 */
class TriggerStatsDto
{
    public function __construct(
        public readonly string $trigger,
        public readonly int $sent = 0,
        public readonly int $failed = 0,
    ) {
    }
}

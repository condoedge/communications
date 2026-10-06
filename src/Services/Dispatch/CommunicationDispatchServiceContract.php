<?php

namespace Condoedge\Communications\Services\Dispatch;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * Runtime send entry point: route a fired trigger through the team-inheritance resolver so a
 * disabled/overridden team is honored at send time (not just in the admin preview).
 */
interface CommunicationDispatchServiceContract
{
    /**
     * Resolve the effective template group for the trigger and fire the notify chain.
     *
     * The service owns the team decision: no team targets the system baseline, one team resolves
     * that team, and several resolve per team so each targeted team's own override / disable is
     * honored instead of being bypassed by a single baseline lookup.
     *
     * @param class-string $trigger
     * @param array|Collection $communicables the recipients to notify
     * @param int[] $communicationTeams every team this send targets
     * @return bool whether anything was actually dispatched
     */
    public function dispatchForTrigger(
        string $trigger,
        CommunicableEvent $event,
        array|Collection $communicables,
        array $communicationTeams = [],
    ): bool;
}

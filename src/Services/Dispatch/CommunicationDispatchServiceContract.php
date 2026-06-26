<?php

namespace Condoedge\Communications\Services\Dispatch;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;

/**
 * Runtime send entry point: route a fired trigger through the team-inheritance resolver so a
 * disabled/overridden team is honored at send time (not just in the admin preview).
 */
interface CommunicationDispatchServiceContract
{
    /**
     * Resolve the effective template group for ($trigger, $teamId) and, when sendable, fire the
     * existing notify chain for the event's communicables. No-op when DISABLED / NONE.
     *
     * A null $teamId targets the system-baseline (team_id IS NULL) template.
     *
     * @param class-string $trigger
     */
    public function dispatchForTrigger(string $trigger, CommunicableEvent $event, ?int $teamId = null): void;
}

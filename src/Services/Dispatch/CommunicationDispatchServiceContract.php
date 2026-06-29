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
     * Resolve the effective template group for ($trigger, $teamId) and, when sendable, fire the
     * notify chain for $communicables. No-op when DISABLED / NONE.
     *
     * @param class-string $trigger
     * @param array|Collection $communicables the recipients to notify
     * @param int|null $teamId the template/header team — null targets the system baseline
     * @param int[] $communicationTeams every team the send is recorded against (recipient team pivot)
     */
    public function dispatchForTrigger(
        string $trigger,
        CommunicableEvent $event,
        array|Collection $communicables,
        ?int $teamId = null,
        array $communicationTeams = [],
    ): void;
}

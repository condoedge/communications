<?php

namespace Condoedge\Communications\Services\Dispatch;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;
use Illuminate\Support\Collection;

class CommunicationDispatchService implements CommunicationDispatchServiceContract
{
    public function __construct(
        protected EffectiveTemplateResolverContract $resolver,
    ) {
    }

    public function dispatchForTrigger(
        string $trigger,
        CommunicableEvent $event,
        array|Collection $communicables,
        ?int $teamId = null,
        array $communicationTeams = [],
    ): void {
        // teamId null => the system-baseline bucket. resolve() with a non-existent team id (0) walks
        // an empty hierarchy and falls straight through to the team_id IS NULL baseline.
        $resolution = $this->resolver->resolve($trigger, $teamId ?? 0);

        if (!$resolution->isSendable() || !$resolution->group) {
            return;
        }

        // team_id is the sending's template/header team; communication_teams is the full set the send
        // is recorded against (the recipient team pivot) so it appears in every team, counted once.
        $params = ContextEnhancer::setContext(array_merge($event->getParams(), [
            'trigger' => $trigger,
            'trigger_instance' => $event,
            'team_id' => $teamId,
            'communication_teams' => $communicationTeams,
        ]))->getEnhancedContext();

        $resolution->group->notify($communicables, null, $params);
    }
}

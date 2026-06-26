<?php

namespace Condoedge\Communications\Services\Dispatch;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\EventsHandling\ScopedCommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;

class CommunicationDispatchService implements CommunicationDispatchServiceContract
{
    public function __construct(
        protected EffectiveTemplateResolverContract $resolver,
    ) {
    }

    public function dispatchForTrigger(string $trigger, CommunicableEvent $event, ?int $teamId = null): void
    {
        // teamId null => the system-baseline bucket. resolve() with a non-existent team id (0) walks
        // an empty hierarchy and falls straight through to the team_id IS NULL baseline.
        $resolution = $this->resolver->resolve($trigger, $teamId ?? 0);

        if (!$resolution->isSendable() || !$resolution->group) {
            return;
        }

        // Channel handlers read $params['trigger_instance'] and type-hint the event's real contract
        // (e.g. TaskCommunicationHandler needs a TaskCommunicableEvent), so expose the ORIGINAL event,
        // not the per-team ScopedCommunicableEvent wrapper — only the communicables are scoped.
        $triggerInstance = $event instanceof ScopedCommunicableEvent ? $event->getInnerEvent() : $event;

        // team_id flows into the send-log header (A3) so each fire records the team it was sent for.
        $params = ContextEnhancer::setContext(array_merge($event->getParams(), [
            'trigger' => $trigger,
            'trigger_instance' => $triggerInstance,
            'team_id' => $teamId,
        ]))->getEnhancedContext();

        $resolution->group->notify($event->getCommunicables(), null, $params);
    }
}

<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A per-team view of a fired event: delegates everything to the original event but exposes only the
 * subset of communicables that belong to one recipient team. The listener creates one of these per
 * recipient team so {@see \Condoedge\Communications\Services\Dispatch\CommunicationDispatchService}
 * resolves the team's own (or inherited) template for exactly those people.
 *
 * Unknown instance calls (e.g. TaskCommunicableEvent::sendToAnotherAssignables) forward to the
 * wrapped event via __call. The static interface methods (getName / validVariablesIds) are only
 * read off the trigger CLASS-STRING at send time, never off this instance, so they stay inert here.
 */
class ScopedCommunicableEvent implements CommunicableEvent
{
    public function __construct(
        protected CommunicableEvent $inner,
        protected Collection $communicables,
    ) {
    }

    public function getParams(): array
    {
        return $this->inner->getParams();
    }

    public function getCommunicables(): Collection|array
    {
        return $this->communicables;
    }

    /** The wrapped (original) event, for callers that need its true identity. */
    public function getInnerEvent(): CommunicableEvent
    {
        return $this->inner;
    }

    public static function getName(): string
    {
        return '';
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }

    /**
     * Forward any other instance method (e.g. sendToAnotherAssignables, getRelatedTaskable) to the
     * wrapped event so channel handlers that read $params['trigger_instance'] keep working.
     */
    public function __call($name, $arguments)
    {
        return $this->inner->{$name}(...$arguments);
    }
}

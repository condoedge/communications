<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\ContextEnhancer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class CommunicationTriggeredListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CommunicableEvent $event)
    {
        $params = (new ContextEnhancer(array_merge($event->getParams(), [
            'trigger' => $event::class,
        ])))->getEnhancedContext();

        $groups = CommunicationTemplateGroup::forTrigger($event::class)->hasValid()->get();

        $groups->each->notify($event->getCommunicables(), null, $params);
    }
}
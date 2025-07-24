<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CommunicationTriggeredListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Enhance the params of the event and notify the communicables
     * 
     * @param \Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent $event
     * @return void
     */
    public function handle(CommunicableEvent $event)
    {
        $params = ContextEnhancer::setContext(array_merge($event->getParams(), [
            'trigger' => $event::class,
        ]))->getEnhancedContext();

        $groups = CommunicationTemplateGroup::forTrigger($event::class)->hasValid()
            ->when(method_exists($event, 'getSpecificCommunicationsIds'), fn($q) => $q->whereIn('id', $event->getSpecificCommunicationsIds()))
            ->get();
        Log::info('Communication groups retrieved', ['groups' => $groups]);
        
        $groups->each->notify($event->getCommunicables(), null, $params);
    }
}
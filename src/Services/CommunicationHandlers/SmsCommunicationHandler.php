<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Illuminate\Support\Facades\Notification;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\SmsCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutSmsCommunicable;

class SmsCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return SmsCommunicable::class;
    }

    // NOTIFICATION

    /**
     * @param SmsCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $layout = $params['layout'] ?? DefaultLayoutSmsCommunicable::class;

        $communicables = collect($communicables)->map(function($communicable) use ($layout, $params) {
            $params = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            Notification::send($communicable->getPhone(), new $layout($this->communication, $params));
        });
    }   
}

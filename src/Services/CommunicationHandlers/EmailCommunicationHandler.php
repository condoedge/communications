<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Illuminate\Support\Facades\Mail;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;


class EmailCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return EmailCommunicable::class;
    }

    // NOTIFICATION

    /**
     * @param EmailCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $layout = $params['layout'] ?? DefaultLayoutEmailCommunicable::class;

        $communicables = collect($communicables)->map(function($communicable) use ($layout, $params) {
            $params = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            Mail::to($communicable->getEmail())->send(new $layout($this->communication, $params));
        });
    }   
}
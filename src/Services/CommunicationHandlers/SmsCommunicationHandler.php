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

        $this->sendEach($communicables, function ($communicable) use ($layout, $params) {
            $phone = $communicable->getPhone();

            if (!$phone) {
                return 'no-phone-number';
            }

            $perRecipientParams = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            $notification = new $layout($this->communication, $perRecipientParams);

            if ($communicable instanceof \Illuminate\Contracts\Translation\HasLocalePreference
                && $locale = $communicable->preferredLocale()) {
                $notification = $notification->locale($locale);
            }

            Notification::route('vonage', $phone)->notify($notification);

            return null;
        });
    }
}

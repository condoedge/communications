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

        foreach ($communicables as $communicable) {
            self::withRecipientLocale($communicable, function () use ($communicable, $layout, $params) {
                $perRecipientParams = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

                $mail = Mail::to($communicable->getEmail());

                if ($communicable instanceof \Illuminate\Contracts\Translation\HasLocalePreference
                    && $locale = $communicable->preferredLocale()) {
                    $mail = $mail->locale($locale);
                }

                $mail->send(new $layout($this->communication, $perRecipientParams));
            });
        }
    }
}
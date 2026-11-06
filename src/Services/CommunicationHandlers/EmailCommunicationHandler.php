<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Recipients\EmailGroup;
use Illuminate\Support\Facades\Log;
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

        $this->sendEach($communicables, function ($communicable) use ($layout, $params) {
            $perRecipientParams = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            $recipients = $this->deliverableAddresses($communicable);

            if ($recipients === []) {
                return 'no-email-address';
            }

            $mail = Mail::to($recipients);

            if ($communicable instanceof \Illuminate\Contracts\Translation\HasLocalePreference
                && $locale = $communicable->preferredLocale()) {
                $mail = $mail->locale($locale);
            }

            $mail->send(new $layout($this->communication, $perRecipientParams));

            return null;
        });
    }

    /** @return string[] */
    protected function deliverableAddresses($communicable): array
    {
        if (!EmailGroup::isGroup($communicable)) {
            $address = $communicable->getEmail();

            return $address && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? [$address] : [];
        }

        $declared = EmailGroup::addressesOf($communicable);

        $deliverable = array_values(array_filter(
            $declared,
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));

        if (count($deliverable) !== count($declared)) {
            Log::warning('Dropped undeliverable addresses from a grouped email recipient', [
                'communication_id' => $this->communication->id,
                'recipient' => get_class($communicable),
                'dropped' => array_values(array_diff($declared, $deliverable)),
                'kept' => count($deliverable),
            ]);
        }

        return $deliverable;
    }
}

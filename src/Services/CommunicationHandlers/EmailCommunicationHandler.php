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

            // Mail::to() accepts a list and puts every entry in the To: header, so a recipient that
            // declared itself a group (see EmailGroup) receives ONE message rather than one per
            // address. A recipient that did not declare it is a one-element list here and produces
            // byte for byte the message it produced before this branch existed.
            $mail = Mail::to($recipients);

            // ONE MESSAGE IS ONE LOCALE, which is why preferredLocale() is still the right hook for
            // a group: the group answers once, for the message, exactly as the senders being
            // replaced resolved one locale for one mail. Nothing per-address has been collapsed —
            // a set of addresses needing different languages needs different messages, which means
            // it is not a group.
            if ($communicable instanceof \Illuminate\Contracts\Translation\HasLocalePreference
                && $locale = $communicable->preferredLocale()) {
                $mail = $mail->locale($locale);
            }

            $mail->send(new $layout($this->communication, $perRecipientParams));

            return null;
        });
    }

    /**
     * The addresses this recipient can actually be written to, in the order it declared them.
     *
     * A GROUP IS NOT ALL-OR-NOTHING. One unusable address among five does not withhold the message
     * from the other four: that would make the group form strictly worse than the fan-out it
     * replaces, where a bad address only ever cost its own recipient. A group is skipped only when
     * nothing in it is deliverable, and it is skipped with the SAME reason string the
     * single-address path uses, so nothing downstream needs a new case.
     *
     * WHAT IS LOST, written here rather than discovered in a support ticket: DeliveryReport records
     * one outcome per POSITION and a group occupies one position, so a dropped address gets no
     * SKIPPED row of its own in communication_sending_recipients — the group's single row is
     * stamped SENT, because a message genuinely went out. The Log::warning below is the only place
     * the dropped address is named, which is why it names it rather than counting it.
     *
     * @return string[]
     */
    protected function deliverableAddresses($communicable): array
    {
        if (!EmailGroup::isGroup($communicable)) {
            // Deliberately NOT wrapped in secureCallCb: a getEmail() that throws has always been a
            // FAILED outcome for that recipient, and swallowing it here would relabel a broken
            // recipient as one that merely has no address — the same row, a different diagnosis.
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

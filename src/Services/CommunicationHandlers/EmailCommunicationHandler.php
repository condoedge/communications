<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Recipients\EmailGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Condoedge\Communications\Models\CommunicationCategory;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Services\Suppression\UnsubscribeLinkGenerator;


class EmailCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return EmailCommunicable::class;
    }

    // TODO: UNCOMMENT THIS IF WE WANT TO EXPOSE THE PLACE WHERE USER CAN UNSUBSCRIBE
    // /**
    //  * Offer the unsubscribe variable in the editor, but only where it resolves. The editor shows
    //  * exactly what the trigger declares, so without this the variable is registered and unreachable.
    //  */
    // protected function editorVariableIds($trigger, $context): ?array
    // {
    //     $ids = parent::editorVariableIds($trigger, $context);

    //     if ($ids === null || !CommunicationCategory::forTrigger($trigger)->isSuppressible()) {
    //         return $ids;
    //     }

    //     return array_values(array_unique([...$ids, 'unsubscribe_link']));
    // }

    // SUPPRESSION

    /** Skip a recipient only when the rules stop every one of their addresses. */
    protected function suppressionReasonFor($communicable): ?string
    {
        // A group's getEmail() is the joined list, which matches no address-level rule: ask per member.
        $addresses = secureCallCb(fn () => EmailGroup::addressesToReach($communicable)) ?? [];

        $reason = null; // none to match; the sender reports 'no-email-address' downstream

        foreach ($addresses as $address) {
            $addressReason = $this->suppression()->reasonFor($this->suppressionContext(), $communicable, $address);

            if (!$addressReason) {
                return null; // someone may still receive it; deliverableAddresses() drops the rest
            }

            $reason ??= $addressReason;
        }

        return $reason;
    }

    protected function category(): CommunicationCategory
    {
        return $this->suppressionContext()->category;
    }

    /**
     * Attach this recipient's own link, which feeds the RFC headers and the footer. Per recipient,
     * never per send: a shared link would unsubscribe whoever clicked it from someone else's address.
     */
    protected function withUnsubscribeParams(array $params, string $recipientEmail): array
    {
        if (!$this->category()->isSuppressible()) {
            return $params;
        }

        // Set even when the caller brought its own link, because this is what arms the mailer-level
        // backstop — the two must never disagree about whether a message is stoppable.
        $params['communication_suppressible'] = true;

        if (empty($params['unsubscribe_url'])) {
            $links = app(UnsubscribeLinkGenerator::class);

            $params['unsubscribe_url'] = $links->urlFor($recipientEmail);
            $params['unsubscribe_mailto'] ??= $links->mailto();
        }

        return $params;
    }

    // NOTIFICATION

    /**
     * @param EmailCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $layout = $params['layout'] ?? config('kompo-communications.email-layout', DefaultLayoutEmailCommunicable::class);

        $this->sendEach($communicables, function ($communicable) use ($layout, $params) {
            $perRecipientParams = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            $recipients = $this->deliverableAddresses($communicable);

            if ($recipients === []) {
                return 'no-email-address';
            }

            $locale = self::recipientLocale($communicable);

            foreach ($this->messagesFor($recipients) as $to) {
                // More than one address only ever shares a message when no link is owed at all.
                $messageParams = count($to) === 1
                    ? $this->withUnsubscribeParams($perRecipientParams, $to[0])
                    : $perRecipientParams;

                $mail = Mail::to($to);

                if ($locale) {
                    $mail = $mail->locale($locale);
                }

                $mail->send(new $layout($this->communication, $messageParams));
            }

            return null;
        });
    }

    /**
     * The To: of each message this recipient becomes. A group shares one message, unless the send
     * is suppressible: a message has a single List-Unsubscribe header, so each member needs a copy
     * of their own to get a link of their own.
     *
     * @param  string[]  $recipients
     * @return string[][]
     */
    protected function messagesFor(array $recipients): array
    {
        return $this->category()->isSuppressible()
            ? array_map(fn (string $address) => [$address], $recipients)
            : [$recipients];
    }

    /** @return string[] */
    protected function deliverableAddresses($communicable): array
    {
        $declared = EmailGroup::addressesToReach($communicable);

        $deliverable = array_values(array_filter(
            $declared,
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));

        if (EmailGroup::isGroup($communicable) && count($deliverable) !== count($declared)) {
            Log::warning('Dropped undeliverable addresses from a grouped email recipient', [
                'communication_id' => $this->communication->id,
                'recipient' => get_class($communicable),
                'dropped' => array_values(array_diff($declared, $deliverable)),
                'kept' => count($deliverable),
            ]);
        }

        // Stopped for some addresses only: not logged, the rest still receive.
        return array_values(array_filter(
            $deliverable,
            fn (string $address) => !$this->suppression()->reasonFor($this->suppressionContext(), $communicable, $address),
        ));
    }
}

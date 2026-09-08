<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Illuminate\Support\Facades\Mail;
use Condoedge\Communications\Models\CommunicationCategory;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Services\Suppression\EmailSuppressionServiceContract;
use Condoedge\Communications\Services\Suppression\UnsubscribeLinkGenerator;


class EmailCommunicationHandler extends AbstractCommunicationHandler
{
    protected ?EmailSuppressionServiceContract $suppressions = null;

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

    /**
     * Drop recipients who withdrew consent, unless this send is transactional. The category comes
     * from the template group, not $params: params only carry the trigger on the dispatch path.
     */
    protected function suppressionReasonFor($communicable): ?string
    {
        if (!$this->category()->isSuppressible()) {
            return null;
        }

        $email = secureCallCb(fn () => $communicable->getEmail());

        if (!$email) {
            return null; // no address to match; the sender reports 'no-email-address' downstream
        }

        return $this->suppressions()->isSuppressed($email) ? 'unsubscribed' : null;
    }

    protected function category(): CommunicationCategory
    {
        return CommunicationCategory::forTrigger($this->communication->group?->trigger);
    }

    /** Resolved once per handler, so one send does not re-resolve the service per recipient. */
    protected function suppressions(): EmailSuppressionServiceContract
    {
        return $this->suppressions ??= app(EmailSuppressionServiceContract::class);
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
        $layout = $params['layout'] ?? DefaultLayoutEmailCommunicable::class;

        $this->sendEach($communicables, function ($communicable) use ($layout, $params) {
            $perRecipientParams = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            $recipientEmail = $communicable->getEmail();

            if (!$recipientEmail || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
                return 'no-email-address';
            }

            $perRecipientParams = $this->withUnsubscribeParams($perRecipientParams, $recipientEmail);

            $mail = Mail::to($recipientEmail);

            if ($communicable instanceof \Illuminate\Contracts\Translation\HasLocalePreference
                && $locale = $communicable->preferredLocale()) {
                $mail = $mail->locale($locale);
            }

            $mail->send(new $layout($this->communication, $perRecipientParams));

            return null;
        });
    }
}
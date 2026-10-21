<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Services\Suppression\EmailSuppressionServiceContract;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Last-resort consent check, as close to the wire as the framework allows.
 *
 * The handler's own filter is the layer that matters for bookkeeping — it records a SKIPPED recipient
 * instead of leaving a silent hole. This exists because that filter can be bypassed: a new send path,
 * a host calling the layout directly, a queued message built before the recipient unsubscribed.
 *
 * Strictly opt-in: only messages carrying the suppressible marker are eligible, so unmarked mail
 * (password resets, activation links, anything outside this package) can never be blocked by a
 * misclassification upstream.
 *
 * CAVEAT: Mailer::shouldSendMessage() dispatches through Event::until(), which halts on the first
 * non-null return. A host listener registered before this one that returns a value (a `return true`
 * at the end of a MessageSending listener is enough) silently disables this backstop. Layer A still
 * applies; only this net is lost.
 */
class BlockSuppressedRecipients
{
    public function __construct(
        protected EmailSuppressionServiceContract $suppressions,
    ) {
    }

    /** @return bool|null false cancels the send; null lets it through. */
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        $headers = $message->getHeaders();

        if (!$headers->has(DefaultLayoutEmailCommunicable::SUPPRESSIBLE_HEADER)) {
            return null;
        }

        $headers->remove(DefaultLayoutEmailCommunicable::SUPPRESSIBLE_HEADER);

        $blocked = [];

        // Cc and Bcc as well as To: a suppressed address receives the message just the same from any
        // of the three, and narrowing only To would let it through the other two.
        foreach (['to', 'cc', 'bcc'] as $field) {
            $kept = [];

            foreach ($message->{'get' . $field}() as $address) {
                $this->suppressions->isSuppressed($address->getAddress())
                    ? $blocked[] = $address->getAddress()
                    : $kept[] = $address;
            }

            $message->{$field}(...$kept);
        }

        if (!$blocked) {
            return null;
        }

        // Logged because reaching here means an upstream filter missed this recipient: the send log
        // will show them SENT, and this line is the only trace of what actually happened.
        Log::info('Mailer backstop blocked suppressed recipients', ['recipients' => $blocked]);

        // Nobody left on any field — cancel rather than send a message addressed to no one.
        return $message->getTo() || $message->getCc() || $message->getBcc() ? null : false;
    }
}

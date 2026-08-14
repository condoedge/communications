<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;

/**
 * The one place that knows how an EmailCommunicable declares "these addresses share ONE message".
 *
 * WHAT IT IS FOR. EmailCommunicationHandler sends one message per communicable, so a recipient list
 * built by fanning an organisation's address list out into N communicables produces N messages. The
 * senders being ported onto this package put the same addresses in ONE message's To: header, which
 * is a different thing to receive: colleagues see each other, and reply-all reaches the whole
 * organisation instead of one address. A recipient that declares the method named below states that
 * it IS such a group, and the handler addresses one message to all of it.
 *
 * DUCK-TYPED, NOT AN INTERFACE, AND THE ABSENCE OF THE METHOD IS THE PERMISSIVE DEFAULT — one
 * address, one message, which is what every EmailCommunicable in every host already means. An
 * interface is permissive only for hosts that hear it exists: a host that never imports it keeps the
 * old behaviour with nothing to notice, and "with nothing to notice" is the failure this seam exists
 * to remove. It is the reason recorded at the getSubject() seam (CommunicationTemplateGroup.php:254)
 * and the shape four other trigger hooks already use.
 *
 * DECLARING THE METHOD IS THE DECISION; THE RETURN VALUE IS ONLY THE MEMBERSHIP. An empty array
 * means "this group is currently reachable at no address" and is reported as a skip. It does NOT
 * fall back to getEmail(): a group's getEmail() is an identity and log value rather than a mailbox
 * (see RecipientGroupOverride::getEmail()), so the fallback would put a non-address in a To: header
 * exactly when the address list failed to resolve.
 *
 * NOT OFFERED TO SMS, AND THAT IS DELIBERATE RATHER THAN PENDING. There is no header in an SMS in
 * which two numbers can see each other, so the property that makes an email group worth having has
 * no analogue: a carrier delivers one message per number whatever the sender groups. Grouping
 * numbers would collapse N real deliveries into one DeliveryReport position and lose per-number
 * delivery status in exchange for nothing. Same for the database and task channels, where a row
 * belongs to exactly one user by construction.
 */
final class EmailGroup
{
    /** The optional method an EmailCommunicable declares in order to become a group. */
    public const DECLARATION = 'getEmailGroup';

    public static function isGroup($communicable): bool
    {
        return $communicable instanceof EmailCommunicable
            && method_exists($communicable, self::DECLARATION);
    }

    /**
     * The addresses as the recipient declared them: NOT validated, NOT deduplicated, NOT reordered.
     *
     * Two things ARE done, and both are about the array being fed straight to Mail::to() rather
     * than about cleaning anybody's data. Non-strings are dropped, because a null or an int there is
     * a TypeError that loses the entire send instead of one address. Surrounding whitespace is
     * stripped, because it is never part of an address and an untrimmed entry either fails the
     * handler's filter_var and silently leaves the To: header or reaches the transport as a
     * malformed address. Nothing else is touched.
     *
     * NORMALISATION STAYS WITH THE HOST because the host is the only party that knows what its
     * stored addresses look like. Coolecto's `comm_emails` is free text typed into a form, and
     * " Ops @Org.TEST " is deliverable today only because the sender being replaced ran trim /
     * strip-inner-spaces / lowercase over it first; a package that guessed at that rule would
     * under-clean one host's data and over-clean another's.
     *
     * DEDUPLICATION IS LEFT ALONE FOR THE SAME REASON. Two identical entries in a To: header is
     * what the caller asked for, and the legacy senders this seam reproduces did not deduplicate
     * either — so removing duplicates here would be this package quietly disagreeing with the
     * behaviour a host is trying to preserve.
     *
     * A THROWING getEmailGroup() IS NOT SWALLOWED HERE. On the send path sendEach() catches it and
     * records that recipient FAILED, which is the correct diagnosis; catching it here would return
     * an empty list and relabel a broken recipient as one that simply has no address. Callers that
     * must not throw — the send-log row write, which runs inside the sending's transaction — wrap
     * their own call instead.
     *
     * @return string[]
     */
    public static function addressesOf($communicable): array
    {
        if (!static::isGroup($communicable)) {
            return [];
        }

        return collect($communicable->{self::DECLARATION}() ?? [])
            ->filter(fn ($address) => is_string($address) && trim($address) !== '')
            ->map(fn (string $address) => trim($address))
            ->values()
            ->all();
    }
}

<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Stable identity for one recipient.
 *
 * Used to deduplicate a fired trigger and to match a delivery outcome back to its persisted
 * recipient row. It MUST survive the queue's serialize/unserialize cycle: spl_object_hash is a
 * per-instance pointer that changes on every retry, which would silently break both callers.
 */
class RecipientKey
{
    public static function for($communicable): string
    {
        $communicable = static::unwrap($communicable);

        if ($communicable instanceof EloquentModel && $communicable->getKey() !== null) {
            return get_class($communicable) . ':' . $communicable->getKey();
        }

        if ($communicable instanceof EmailCommunicable) {
            $group = static::groupIdentity($communicable);

            if ($group !== null) {
                return $group;
            }

            $email = secureCallCb(fn () => $communicable->getEmail());

            if ($email) {
                return 'email:' . mb_strtolower((string) $email);
            }
        }

        // serialize() reproduces the same bytes for the same object state, so an unsaved or
        // keyless recipient still hashes consistently across a retry.
        return 'ser:' . (secureCallCb(fn () => md5(serialize($communicable))) ?? spl_object_hash($communicable));
    }

    /**
     * Identity of a grouped recipient — WHICH mailboxes share the message, not the order they
     * arrived in. Returns null when this recipient is not a group, or is a group with nothing in it.
     *
     * SORTED BECAUSE ORDER IS NOT STABLE AND THE FAILURE IS SILENT. A group's addresses come back
     * in whatever order the host's query produced, so keying on the declared order makes one
     * accidental double-fire look like two different sends: the whole organisation is mailed twice
     * inside the replay window, nothing errors, and the only witnesses are the people who got the
     * duplicate. Order still decides the To: header, which is why it is preserved everywhere the
     * addresses are rendered and dropped only here, where the question is identity.
     *
     * HASHING THE LIST WAS REJECTED FOR THE EMPTY CASE — returning null instead. Every group with
     * no resolvable address hashes to the same digest, so unreachable organisations would all share
     * one identity and deduplicate each other; an organisation with no address today is precisely
     * the one about to have one entered, so that collision lands on live sends. Falling through
     * leaves such a group on the serialize() path, where its own state keeps it distinct.
     *
     * A DISTINCT PREFIX, NOT A JOINED 'email:' STRING, so a group can never collide with an
     * individual — including the one-address group, which the handler treats exactly like a plain
     * recipient but which is a standing audience that may gain a member before the next fire.
     */
    protected static function groupIdentity($communicable): ?string
    {
        if (!EmailGroup::isGroup($communicable)) {
            return null;
        }

        // Defensive here but not on the send path: a throwing getEmailGroup() must still be able to
        // FAIL its recipient loudly in sendEach(), whereas this runs while the trigger is deciding
        // whether it has already fired, where an exception would lose the whole dispatch instead.
        $addresses = secureCallCb(fn () => EmailGroup::addressesOf($communicable)) ?? [];

        if ($addresses === []) {
            return null;
        }

        $normalized = array_map(fn (string $address) => mb_strtolower($address), $addresses);
        sort($normalized);

        return 'emails:' . md5(implode(',', $normalized));
    }

    /** RecipientOverride decorates the real recipient at send time; identity is the underlying one. */
    public static function unwrap($communicable)
    {
        return $communicable instanceof RecipientOverride ? $communicable->getInner() : $communicable;
    }
}

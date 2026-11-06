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

    protected static function groupIdentity($communicable): ?string
    {
        if (!EmailGroup::isGroup($communicable)) {
            return null;
        }

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

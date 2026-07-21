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
            $email = secureCallCb(fn () => $communicable->getEmail());

            if ($email) {
                return 'email:' . mb_strtolower((string) $email);
            }
        }

        // serialize() reproduces the same bytes for the same object state, so an unsaved or
        // keyless recipient still hashes consistently across a retry.
        return 'ser:' . (secureCallCb(fn () => md5(serialize($communicable))) ?? spl_object_hash($communicable));
    }

    /** RecipientOverride decorates the real recipient at send time; identity is the underlying one. */
    public static function unwrap($communicable)
    {
        return $communicable instanceof RecipientOverride ? $communicable->getInner() : $communicable;
    }
}

<?php

namespace Condoedge\Communications\Models;

/**
 * Why an address stopped receiving mail. Only USER_UNSUBSCRIBE is written today; the provider
 * feedback cases exist so bounce handling can reuse this table rather than start a second list.
 */
enum EmailSuppressionReason: string
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case USER_UNSUBSCRIBE = 'user_unsubscribe';
    case BOUNCE = 'bounce';
    case COMPLAINT = 'complaint';
    case ADMIN = 'admin';

    public function label()
    {
        return match ($this) {
            self::USER_UNSUBSCRIBE => __('communications.suppression-reason-user-unsubscribe'),
            self::BOUNCE => __('communications.suppression-reason-bounce'),
            self::COMPLAINT => __('communications.suppression-reason-complaint'),
            self::ADMIN => __('communications.suppression-reason-admin'),
        };
    }
}

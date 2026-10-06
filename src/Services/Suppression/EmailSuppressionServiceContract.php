<?php

namespace Condoedge\Communications\Services\Suppression;

use Condoedge\Communications\Models\CommunicationEmailSuppression;
use Condoedge\Communications\Models\EmailSuppressionReason;

/**
 * The one place that answers "may we email this address?".
 *
 * Bound in the service provider so a host can swap in its own store (a provider-side suppression
 * list, a shared blacklist) without touching the send path.
 */
interface EmailSuppressionServiceContract
{
    /**
     * Whether this address has withdrawn consent for non-transactional mail.
     *
     * Callers decide the category question BEFORE asking this — a transactional send never asks.
     */
    public function isSuppressed(string $email): bool;

    public function suppress(
        string $email,
        EmailSuppressionReason $reason = EmailSuppressionReason::USER_UNSUBSCRIBE,
        ?string $source = null,
    ): CommunicationEmailSuppression;

    /**
     * Restore delivery. Returns null when the address was never suppressed — resubscribing someone
     * who never left writes nothing.
     */
    public function allow(string $email, ?string $source = null): ?CommunicationEmailSuppression;
}

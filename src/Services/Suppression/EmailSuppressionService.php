<?php

namespace Condoedge\Communications\Services\Suppression;

use Condoedge\Communications\Models\CommunicationEmailSuppression;
use Condoedge\Communications\Models\EmailSuppressionReason;

class EmailSuppressionService implements EmailSuppressionServiceContract
{
    /**
     * Memoized per instance, and the service is bound (not a singleton) so an instance lives for one
     * send. A process-wide cache would keep skipping someone for hours after they resubscribed,
     * because a queue worker never reaches request termination to flush it.
     *
     * @var array<string, bool>
     */
    protected array $memo = [];

    public function isSuppressed(string $email): bool
    {
        $email = CommunicationEmailSuppression::normalizeEmail($email);

        if ($email === '') {
            return false;
        }

        return $this->memo[$email] ??= CommunicationEmailSuppression::query()
            ->forEmail($email)
            ->global()
            ->active()
            ->exists();
    }

    public function suppress(
        string $email,
        EmailSuppressionReason $reason = EmailSuppressionReason::USER_UNSUBSCRIBE,
        ?string $source = null,
    ): CommunicationEmailSuppression {
        $email = CommunicationEmailSuppression::normalizeEmail($email);

        // withTrashed: the unique index still counts a soft-deleted row, so resolving one here is the
        // difference between restoring it and a duplicate-key error on a public endpoint.
        $row = CommunicationEmailSuppression::withTrashed()
            ->forEmail($email)
            ->global()
            ->first() ?? new CommunicationEmailSuppression();

        $row->email = $email;
        $row->category = CommunicationEmailSuppression::GLOBAL_CATEGORY;
        $row->reason = $reason;
        $row->source = $source;
        $row->unsubscribed_at = now();
        $row->resubscribed_at = null;
        $row->deleted_at = null;
        $row->save();

        unset($this->memo[$email]);

        return $row;
    }

    public function allow(string $email, ?string $source = null): ?CommunicationEmailSuppression
    {
        $email = CommunicationEmailSuppression::normalizeEmail($email);

        // At most one row can match: (email, category) is unique and category is never null here.
        $row = CommunicationEmailSuppression::query()
            ->forEmail($email)
            ->global()
            ->active()
            ->first();

        // Never suppressed, or already resubscribed: writing a row here would invent a consent event.
        if (!$row) {
            return null;
        }

        $row->unsubscribed_at = null;
        $row->resubscribed_at = now();
        $row->source = $source;
        $row->save();

        unset($this->memo[$email]);

        return $row;
    }
}

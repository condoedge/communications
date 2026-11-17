<?php

namespace Condoedge\Communications\Services\Suppression\Rules;

use Condoedge\Communications\Models\CommunicationEmailSuppression;
use Condoedge\Communications\Models\EmailSuppressionReason;
use Condoedge\Communications\Recipients\EmailGroup;
use Condoedge\Communications\Services\Suppression\EmailSuppressionServiceContract;
use Condoedge\Communications\Services\Suppression\SuppressionContext;

/**
 * The address-level list: a bounce stops every category, a withdrawn consent only the
 * categories an unsubscribe reaches.
 */
class EmailSuppressionListRule implements SuppressionRule
{
    public function __construct(
        protected EmailSuppressionServiceContract $suppressions,
    ) {
    }

    public function prime(SuppressionContext $context, array $communicables): void
    {
        $addresses = [];

        foreach ($communicables as $communicable) {
            array_push($addresses, ...(secureCallCb(fn () => EmailGroup::addressesToReach($communicable)) ?? []));
        }

        $this->suppressions->reasonsFor($addresses);
    }

    public function reasonFor(SuppressionContext $context, $communicable, ?string $address = null): ?string
    {
        if ($address === null) {
            return null;
        }

        $reason = $this->suppressions->reasonsFor([$address])[CommunicationEmailSuppression::normalizeEmail($address)] ?? null;

        if ($reason === EmailSuppressionReason::BOUNCE) {
            return 'bounced';
        }

        return $reason && $context->category->isSuppressible() ? 'unsubscribed' : null;
    }
}

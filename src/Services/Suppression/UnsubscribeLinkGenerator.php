<?php

namespace Condoedge\Communications\Services\Suppression;

use Condoedge\Communications\Models\CommunicationEmailSuppression;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * Mints and reads the per-recipient unsubscribe link.
 *
 * The address travels encrypted inside a signed URL: signing stops anyone editing whose address it
 * is, encrypting keeps a scraped header from disclosing it. Links never expire — a message sits in
 * an inbox for years, and a dead unsubscribe link is what bulk-sender policy penalises.
 */
class UnsubscribeLinkGenerator
{
    public const ROUTE_UNSUBSCRIBE = 'condoedge-comms.unsubscribe';
    public const ROUTE_UNSUBSCRIBE_POST = 'condoedge-comms.unsubscribe.post';

    public function tokenFor(string $email): string
    {
        return Crypt::encryptString(CommunicationEmailSuppression::normalizeEmail($email));
    }

    /** Null rather than a throw: a mangled link is a 404 page, not a 500. */
    public function emailFromToken(?string $token): ?string
    {
        if (!$token) {
            return null;
        }

        try {
            $email = Crypt::decryptString($token);
        } catch (\Throwable $e) {
            return null;
        }

        return $email === '' ? null : CommunicationEmailSuppression::normalizeEmail($email);
    }

    public function urlFor(string $email): string
    {
        return URL::signedRoute(self::ROUTE_UNSUBSCRIBE, $this->params($email));
    }

    /**
     * The locale rides along because the recipient arrives as a guest with no session and no cookie,
     * so the page would otherwise answer a French member in the app default. Links are minted inside
     * AbstractCommunicationHandler::withRecipientLocale(), so this is already their language.
     */
    protected function params(string $email): array
    {
        return [
            'token' => $this->tokenFor($email),
            'locale' => app()->getLocale(),
        ];
    }

    /** The mailto: side of List-Unsubscribe, when the host configured one. */
    public function mailto(): ?string
    {
        return config('kompo-communications.unsubscribe.mailto') ?: null;
    }
}

<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable;

/**
 * RecipientOverride for a model that must be written to at SEVERAL addresses in ONE message.
 *
 * The case it exists for: a supplier, an organisation, a department — one entity, one body, several
 * mailboxes that the sender being replaced put together in a single To: header. Decorating rather
 * than replacing the model is what keeps the send log honest: getInner() is still the model, so
 * communication_sending_recipients.recipient_type/_id morph to it and the ContextEnhancer keys the
 * render context under the model's own getContextKey().
 *
 * A SEPARATE CLASS RATHER THAN A withEmails() METHOD ON RecipientOverride, and the reason is that
 * the group seam is duck-typed: method_exists() is a property of the CLASS, so a getEmailGroup() on
 * RecipientOverride would make EVERY override a group whether or not it was built as one. Coolecto's
 * CampaignStartingProductsReview is the live proof that would break — it builds one plain
 * RecipientOverride per (supplier, address) pair on purpose, and turning those into one group would
 * merge several suppliers' addresses into a single message carrying one supplier's unit costs.
 *
 * The factory is `forGroup()` rather than an override of `for()`: PHP checks static method
 * signatures for compatibility, so adding a required second parameter to the inherited `for()` is a
 * fatal at class-load time rather than a nicer API.
 *
 * ONE MESSAGE IS ONE LANGUAGE, and the group takes it from RecipientOverride::withLocale() like any
 * other override — `forGroup($model, $emails)->withLocale($language)`. A group with no locale
 * renders in whatever locale the worker is on, which for a queued send is config('app.locale'); a
 * caller replacing a sender that localised the mail from something in its payload must pass that
 * something, because nothing here can infer it. A set of addresses needing DIFFERENT languages
 * cannot be one group: it is several messages, so it is several recipients.
 */
class RecipientGroupOverride extends RecipientOverride
{
    /** @var string[] */
    protected array $emailGroup = [];

    /**
     * @param  string[]  $emails  the addresses that share the message, already normalised by the
     *                            caller — see EmailGroup::addressesOf() for why the package does not
     *                            normalise them for you
     */
    public static function forGroup(Communicable $inner, array $emails): static
    {
        $override = new static($inner);
        $override->emailGroup = array_values($emails);

        return $override;
    }

    /** @return string[] */
    public function getEmailGroup(): array
    {
        return $this->emailGroup;
    }

    /**
     * THE WHOLE LIST, JOINED — NOT ONE MEMBER OF IT, and not a mailbox.
     *
     * Nothing on the send path mails this value: the handler takes the group branch and
     * CommunicationSending derives the log column from getEmailGroup(). What still reads it is
     * RecipientKey::for(), where the joined string is exactly right — a group is a different
     * recipient from any one of its members, and keying it on the first address would let a send to
     * the group and a send to that member deduplicate each other.
     *
     * The joined form is also the SAFE answer for any reader this class does not know about,
     * because it fails filter_var: a caller that took the single-address path by mistake skips the
     * recipient and says so, instead of quietly mailing one address out of five.
     */
    public function getEmail()
    {
        return implode(', ', $this->emailGroup);
    }
}

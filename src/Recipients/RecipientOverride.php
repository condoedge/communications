<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\ChannelAware;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\SmsCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\TaskCommunicable;
use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * RecipientOverride
 *
 * Fluent, send-time decorator around any Communicable. Lets callers swap the
 * email/phone/locale for a single send and/or restrict which channels actually
 * fire for that recipient.
 *
 * Statically declares every channel contract; runtime gating happens through
 * the ChannelAware hook consumed by AbstractCommunicationHandler::notify().
 */
class RecipientOverride implements
    EmailCommunicable,
    SmsCommunicable,
    DatabaseCommunicable,
    TaskCommunicable,
    ChannelAware,
    HasLocalePreference
{
    protected Communicable $inner;

    /** @var array<string, mixed> e.g. ['email' => '...', 'phone' => '...', 'locale' => 'fr'] */
    protected array $overrides = [];

    /** @var array<int, class-string>|null null = all channels the inner supports */
    protected ?array $allowedChannels = null;

    /** @var array<int, class-string>|null null = no channels denied */
    protected ?array $deniedChannels = null;

    public function __construct(Communicable $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Wrap any Communicable. The inner does NOT need to implement every
     * channel — acceptsChannel() handles the gating.
     */
    public static function for(Communicable $inner): self
    {
        return new self($inner);
    }

    /** The wrapped recipient — used for send-log identity (morph). */
    public function getInner(): Communicable
    {
        return $this->inner;
    }

    public function withEmail(string $email): self
    {
        $this->overrides['email'] = $email;
        return $this;
    }

    public function withPhone(string $phone): self
    {
        $this->overrides['phone'] = $phone;
        return $this;
    }

    /**
     * The language THIS send must be written in. null leaves the ambient locale alone.
     *
     * WITHOUT THIS SEAM A DECORATED SEND CANNOT CARRY A LANGUAGE AT ALL, and the failure is silent:
     * both readers test `instanceof HasLocalePreference` on the OUTER object
     * (EmailCommunicationHandler.php:52, AbstractCommunicationHandler.php:241), so before this class
     * declared the interface the message rendered in whatever locale the process happened to be on —
     * for a queued send that is config('app.locale'), which has nothing to do with the recipient.
     * Nothing fails; a correct-looking message ships in the wrong language. Measured in Coolecto:
     * CampaignStartingProductsReview's legacy sender rendered fr from the campaign and its ported
     * form rendered the ambient en, and every test it had still passed.
     *
     * FORWARDING TO THE INNER WHEN THE INNER IS A HasLocalePreference WAS REJECTED, although the
     * decorator forwards every other accessor and Coolecto's own port contract proposed it. It is
     * not inert for hosts that made no edit: SISC wraps App\Models\Crm\Person — which implements
     * HasLocalePreference — at seven event classes, and 91 of its 338,653 persons resolve a non-null
     * preferredLocale(), so those sends would change language on a package upgrade alone, with no
     * host edit and no gate to hold it. Answering only what a caller passed here leaves
     * every existing wrapper exactly as it was, and a host that WANTS the inner's language passes
     * `$inner->preferredLocale()` and says so at the call site.
     *
     * Which is also why an explicit null is not distinguished from "never called": with no
     * forwarding the two mean the same thing, and both package readers short-circuit on a falsy
     * locale, so `->withLocale($campaign?->getLanguage())` on a deleted campaign degrades to today's
     * behaviour rather than to a different one.
     */
    public function withLocale(?string $locale): self
    {
        $this->overrides['locale'] = $locale;
        return $this;
    }

    /**
     * Restrict the recipient to the given channel interfaces.
     *
     * @param array<int, class-string> $interfaces
     */
    public function onlyChannels(array $interfaces): self
    {
        $this->allowedChannels = array_values($interfaces);
        return $this;
    }

    /**
     * Allow every channel EXCEPT the given ones.
     *
     * @param array<int, class-string> $interfaces
     */
    public function exceptChannels(array $interfaces): self
    {
        $this->deniedChannels = array_values($interfaces);
        return $this;
    }

    // -- Channel-interface methods ------------------------------------------

    public function getEmail()
    {
        return $this->overrides['email'] ?? $this->inner->getEmail();
    }

    public function getPhone()
    {
        return $this->overrides['phone'] ?? $this->inner->getPhone();
    }

    /** Only what withLocale() was given — see there for why the inner is deliberately not consulted. */
    public function preferredLocale()
    {
        return $this->overrides['locale'] ?? null;
    }

    public function getUserId()
    {
        return $this->inner->getUserId();
    }

    public function hasTeam($teamId)
    {
        return $this->inner->hasTeam($teamId);
    }

    public function getId()
    {
        return $this->inner->getId();
    }

    public function getContextKey()
    {
        return $this->inner->getContextKey();
    }

    public function label()
    {
        return $this->inner->label();
    }

    // -- Communicable base scope methods ------------------------------------
    //
    // The decorator is constructed at send-time, never queried. These exist
    // only to satisfy the Communicable interface contract; calling them is
    // a programmer error.

    public function scopeValidForCommunication($query)
    {
        throw new \LogicException('Cannot query a RecipientOverride');
    }

    public function scopeSearch($query, $search)
    {
        throw new \LogicException('Cannot query a RecipientOverride');
    }

    // -- ChannelAware -------------------------------------------------------

    public function acceptsChannel(string $channelInterface): bool
    {
        if (!$this->inner instanceof $channelInterface) {
            return false;
        }

        if ($this->allowedChannels !== null) {
            return in_array($channelInterface, $this->allowedChannels, true);
        }

        if ($this->deniedChannels !== null) {
            return !in_array($channelInterface, $this->deniedChannels, true);
        }

        return true;
    }
}

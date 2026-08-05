<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\ChannelAware;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\SmsCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\TaskCommunicable;

/**
 * RecipientOverride
 *
 * Fluent, send-time decorator around any Communicable. Lets callers swap the
 * email/phone for a single send and/or restrict which channels actually fire
 * for that recipient.
 *
 * Statically declares every channel contract; runtime gating happens through
 * the ChannelAware hook consumed by AbstractCommunicationHandler::notify().
 */
class RecipientOverride implements
    EmailCommunicable,
    SmsCommunicable,
    DatabaseCommunicable,
    TaskCommunicable,
    ChannelAware
{
    protected Communicable $inner;

    /** @var array<string, mixed> e.g. ['email' => '...', 'phone' => '...'] */
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

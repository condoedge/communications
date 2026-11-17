<?php

namespace Condoedge\Communications\Services\Suppression\Rules;

use Condoedge\Communications\Models\CommunicationEmailSuppression;
use Condoedge\Communications\Recipients\EmailGroup;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\Preferences\AddressPreferences;
use Condoedge\Communications\Services\Preferences\CommunicationPreferenceServiceContract;
use Condoedge\Communications\Services\Suppression\SuppressionContext;

/**
 * What the address itself chose to receive: nothing, or not this group, or not this trigger.
 * A mandatory trigger ignores the group and trigger choices, and always reaches at least one address.
 */
class RecipientPreferenceRule implements SuppressionRule
{
    /** @var array<string, AddressPreferences> keyed by normalized address */
    protected array $preferences = [];

    public function __construct(
        protected CommunicationPreferenceServiceContract $service,
    ) {
    }

    public function prime(SuppressionContext $context, array $communicables): void
    {
        if (!$this->appliesTo($context)) {
            return;
        }

        $addresses = [];

        foreach ($communicables as $communicable) {
            array_push($addresses, ...(secureCallCb(fn () => EmailGroup::addressesToReach($communicable)) ?? []));
        }

        $this->load($addresses);
    }

    public function reasonFor(SuppressionContext $context, $communicable, ?string $address = null): ?string
    {
        if ($address === null || !$this->appliesTo($context)) {
            return null;
        }

        $mandatory = !$context->canBeTakenOff();

        if (!$this->of($address)->receivesNotifications()) {
            // Mandatory mail must land somewhere: the switch only diverts it when another address takes it.
            return $mandatory && !$this->anotherAddressReceives($communicable, $address) ? null : 'address-notifications-off';
        }

        if ($mandatory) {
            return null;
        }

        return $this->of($address)->receivesTrigger($context->trigger, $this->service->groupOf($context->trigger))
            ? null
            : 'turned-off-by-recipient';
    }

    protected function appliesTo(SuppressionContext $context): bool
    {
        return config('kompo-communications.address-preferences.enabled', true)
            && $context->channel === EmailCommunicable::class
            && (bool) $context->trigger;
    }

    protected function anotherAddressReceives($communicable, string $address): bool
    {
        $others = array_filter(
            secureCallCb(fn () => EmailGroup::addressesToReach($communicable)) ?? [],
            fn (string $other) => $this->key($other) !== $this->key($address),
        );

        foreach ($others as $other) {
            if ($this->of($other)->receivesNotifications()) {
                return true;
            }
        }

        return false;
    }

    protected function of(string $address): AddressPreferences
    {
        $this->load([$address]);

        return $this->preferences[$this->key($address)];
    }

    /** @param string[] $addresses */
    protected function load(array $addresses): void
    {
        $unknown = array_filter($addresses, fn (string $address) => !isset($this->preferences[$this->key($address)]));

        if ($unknown) {
            $this->preferences += $this->service->preferencesOf($unknown);
        }
    }

    protected function key(string $address): string
    {
        return CommunicationEmailSuppression::normalizeEmail($address);
    }
}

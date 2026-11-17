<?php

namespace Condoedge\Communications\Services\Preferences;

use Condoedge\Communications\Models\CommunicationPreference;

/**
 * The choices of one address. The most specific one wins: a trigger over its group, and
 * anything left unset is received.
 */
final class AddressPreferences
{
    /** @param array<string, bool> $choices keyed by "scope:key" */
    public function __construct(
        protected array $choices = [],
    ) {
    }

    /** The address-wide switch: off, the address is only a contact address. */
    public function receivesNotifications(): bool
    {
        return $this->choices[CommunicationPreference::SCOPE_ALL . ':'] ?? true;
    }

    public function receivesGroup(?string $group): bool
    {
        return $this->choices[CommunicationPreference::SCOPE_GROUP . ':' . $group] ?? true;
    }

    public function receivesTrigger(string $trigger, ?string $group): bool
    {
        return $this->choices[CommunicationPreference::SCOPE_TRIGGER . ':' . $trigger] ?? $this->receivesGroup($group);
    }
}

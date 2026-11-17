<?php

namespace Condoedge\Communications\Services\Preferences;

use Illuminate\Support\Collection;

/** What each address chose to receive: everything, per group of triggers, per trigger. */
interface CommunicationPreferenceServiceContract
{
    /**
     * Triggers that send an email as seen from this team, by group. One a team disabled is not offered.
     *
     * @return Collection<string, Collection<int, class-string>> keyed by group value ('' when ungrouped)
     */
    public function offeredTriggersByGroup(int $teamId): Collection;

    /** A mandatory trigger is sent whatever the group or trigger choice says. */
    public function isMandatory(string $trigger): bool;

    public function groupOf(string $trigger): ?string;

    /**
     * The choices of many addresses in one read.
     *
     * @param string[] $emails
     * @return array<string, AddressPreferences> keyed by normalized address, one entry per address asked
     */
    public function preferencesOf(array $emails): array;

    public function setReceivesNotifications(string $email, bool $enabled): void;

    /** Choosing for a group clears the choices made for its triggers: the group switch sets them all. */
    public function setGroup(string $email, string $group, bool $enabled): void;

    public function setTrigger(string $email, string $trigger, bool $enabled): void;
}

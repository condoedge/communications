<?php

namespace Condoedge\Communications\Services\Preferences;

use Condoedge\Communications\Models\CommunicationCategory;
use Condoedge\Communications\Models\CommunicationEmailSuppression;
use Condoedge\Communications\Models\CommunicationPreference;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\Grouping\TriggerGroupResolverContract;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;
use Illuminate\Support\Collection;

class CommunicationPreferenceService implements CommunicationPreferenceServiceContract
{
    public function __construct(
        protected EffectiveTemplateResolverContract $templates,
        protected TriggerGroupResolverContract $groups,
    ) {
    }

    public function offeredTriggersByGroup(int $teamId): Collection
    {
        // A hand-fired trigger names its own groups: it has no template to resolve, and is always offered.
        return collect(config('kompo-communications.triggers', []))
            ->filter(fn (string $trigger) => method_exists($trigger, 'getSpecificCommunicationsIds') || $this->sendsEmail($trigger, $teamId))
            ->groupBy(fn (string $trigger) => (string) $this->groupOf($trigger));
    }

    public function isMandatory(string $trigger): bool
    {
        return !CommunicationCategory::canBeTakenOff($trigger);
    }

    public function groupOf(string $trigger): ?string
    {
        return $this->groups->groupFor($trigger)?->value();
    }

    public function preferencesOf(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(CommunicationEmailSuppression::normalizeEmail(...), $emails))));
        $choices = array_fill_keys($emails, []);

        foreach (array_chunk($emails, 1000) as $chunk) {
            foreach (CommunicationPreference::query()->forEmails($chunk)->get(['email', 'scope', 'key', 'enabled']) as $row) {
                $choices[CommunicationEmailSuppression::normalizeEmail($row->email)][$row->scope . ':' . $row->key] = $row->enabled;
            }
        }

        return array_map(fn (array $ofAddress) => new AddressPreferences($ofAddress), $choices);
    }

    public function setReceivesNotifications(string $email, bool $enabled): void
    {
        $this->set($email, CommunicationPreference::SCOPE_ALL, '', $enabled);
    }

    public function setGroup(string $email, string $group, bool $enabled): void
    {
        $this->set($email, CommunicationPreference::SCOPE_GROUP, $group, $enabled);

        $triggers = collect(config('kompo-communications.triggers', []))
            ->filter(fn (string $trigger) => (string) $this->groupOf($trigger) === $group)
            ->values()->all();

        CommunicationPreference::query()
            ->forEmails([CommunicationEmailSuppression::normalizeEmail($email)])
            ->where('scope', CommunicationPreference::SCOPE_TRIGGER)
            ->whereIn('key', $triggers)
            ->forceDelete();
    }

    public function setTrigger(string $email, string $trigger, bool $enabled): void
    {
        $this->set($email, CommunicationPreference::SCOPE_TRIGGER, $trigger, $enabled);
    }

    protected function set(string $email, string $scope, string $key, bool $enabled): void
    {
        $email = CommunicationEmailSuppression::normalizeEmail($email);

        // withTrashed: the unique index still counts a soft-deleted row.
        $row = CommunicationPreference::withTrashed()->forEmails([$email])->where('scope', $scope)->where('key', $key)->first()
            ?? new CommunicationPreference();

        $row->email = $email;
        $row->scope = $scope;
        $row->key = $key;
        $row->enabled = $enabled;
        $row->deleted_at = null;
        $row->save();
    }

    protected function sendsEmail(string $trigger, int $teamId): bool
    {
        $template = $this->templates->resolveChannel($trigger, $teamId, CommunicationType::EMAIL);

        return $template !== null && !$template->is_draft;
    }
}

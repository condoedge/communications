<?php

namespace Condoedge\Communications\Services\TemplateSeeding;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TemplateSeedingService implements TemplateSeedingServiceContract
{
    public function getMissingTriggers(): Collection
    {
        return collect(CommunicationTemplateGroup::getTriggers())
            ->reject(fn ($trigger) => $this->baselineExists($trigger))
            ->values();
    }

    public function seedAll(): Collection
    {
        return $this->getMissingTriggers()
            ->map(fn ($trigger) => $this->seedForTrigger($trigger))
            ->filter()
            ->values();
    }

    public function seedForTrigger(string $trigger): ?CommunicationTemplateGroup
    {
        // Seeding targets the system baseline (team_id NULL) specifically, independent of whether a
        // team already configured its own override. Scoping to the baseline is what makes this safe
        // to run on every deploy: a team owning a group must not make the trigger look seeded and
        // leave every other team resolving NONE.
        return DB::transaction(function () use ($trigger) {
            if ($this->baselineExists($trigger, lock: true)) {
                return null;
            }

            $group = CommunicationTemplateGroup::createForTrigger($trigger);

            if ($group) {
                $this->applyDefaultButtonHandler($group, $trigger);
            }

            return $group;
        });
    }

    /**
     * Whether the system-baseline group already exists for the trigger. A disabled baseline still
     * counts as existing, so re-seeding never revives one an admin turned off. The optional row lock
     * closes the SELECT-then-INSERT window against concurrent deploys creating duplicate baselines.
     */
    protected function baselineExists(string $trigger, bool $lock = false): bool
    {
        return CommunicationTemplateGroup::where('trigger', $trigger)
            ->whereNull('team_id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->exists();
    }

    /**
     * If the trigger restricts the handler set via `validNotificationButtonHandlers()`, pre-select
     * the first non-default handler on the freshly-seeded DB notification template. Without this,
     * the seeded notification card would render with no actionable button until an admin opens
     * the form and selects a handler manually.
     */
    protected function applyDefaultButtonHandler(CommunicationTemplateGroup $group, string $trigger): void
    {
        if (!method_exists($trigger, 'validNotificationButtonHandlers')) {
            return;
        }

        $allowed = $trigger::validNotificationButtonHandlers([]);
        if (!is_array($allowed) || empty($allowed)) {
            return;
        }

        $defaultHandler = config(
            'kompo-auth.notifications.default_notification_button_handler',
            \Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler::class
        );

        $picked = collect($allowed)->first(fn ($h) => $h !== $defaultHandler);

        if (!$picked) {
            return;
        }

        $dbTemplate = $group->communicationTemplates()
            ->where('type', \Condoedge\Communications\Models\CommunicationType::DATABASE->value)
            ->first();

        if (!$dbTemplate) {
            return;
        }

        \Condoedge\Communications\Models\NotificationTemplate::where('communication_id', $dbTemplate->id)
            ->update(['custom_button_handler' => $picked]);
    }
}

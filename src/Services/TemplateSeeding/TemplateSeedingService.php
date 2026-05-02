<?php

namespace Condoedge\Communications\Services\TemplateSeeding;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Illuminate\Support\Collection;

class TemplateSeedingService implements TemplateSeedingServiceContract
{
    public function getMissingTriggers(): Collection
    {
        return collect(CommunicationTemplateGroup::getTriggers())
            ->reject(fn ($trigger) => CommunicationTemplateGroup::where('trigger', $trigger)->exists())
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
        if (CommunicationTemplateGroup::where('trigger', $trigger)->exists()) {
            return null;
        }

        $group = CommunicationTemplateGroup::createForTrigger($trigger);

        if ($group) {
            $this->applyDefaultButtonHandler($group, $trigger);
        }

        return $group;
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

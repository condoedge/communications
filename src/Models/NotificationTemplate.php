<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

// Just using kompo/auth for this, we should see how to decouple this
// Using facade utils to abstract and put those one in the config
use Kompo\Auth\Models\Monitoring\Notification;
use Kompo\Auth\Models\Monitoring\NotificationTypeEnum;

class NotificationTemplate extends Model
{
    use \Kompo\Database\HasTranslations;

    public function communication()
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }

    protected $translatable = [
        'custom_button_text',
    ];

    /**
     * Cols: custom_button_text, custom_button_href, has_reminder_button, custom_button_handler
     * Custom button handler is for custom actions like opening a modal, it should be a string linked to an static handler method.
     */

    public function scopeForCommunication($query, $communicationId)
    {
        return $query->where('communication_id', $communicationId);
    }

    /**
     * A notification row is written per (recipient, targeted team) pair, so a recipient belonging to
     * none of the targeted teams gets nothing. The keys of the recipients actually written are
     * returned so the caller can tell delivery apart from a silent no-op.
     *
     * @param \Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable[] $communicables
     * @param mixed $params
     * @return int[] keys of $communicables that got at least one notification
     */
    public function sendNotification(array $communicables, $params = []): array
    {
        $notifications = [];
        $delivered = [];

        if (empty($params['teams_ids'])) {
            Log::warning('Database notification has no target team; nothing will be written', [
                'notification_template_id' => $this->id,
                'trigger' => $params['trigger'] ?? null,
            ]);

            return [];
        }

        foreach ($communicables as $position => $communicable) {
            // $params stays by value: enhancing it back into a shared variable would carry the first
            // recipient's derived context into every later one, and explicit context outranks
            // derived values, so the stale data would win.
            AbstractCommunicationHandler::withRecipientLocale($communicable, function () use ($communicable, $params, $position, &$notifications, &$delivered) {
                foreach ($params['teams_ids'] ?? [] as $teamId) {
                    if (!$teamId || !$communicable->hasTeam($teamId)) {
                        continue;
                    }

                    // setContext() replaces the whole context, so it has to come first — the other
                    // order throws the communicable away and the recipient enhancer never runs.
                    $recipientParams = ContextEnhancer::setContext($params)->setCommunicable($communicable)->getEnhancedContext();

                    ContentReplacer::injectContext($recipientParams);

                    $notifications[] = [
                        'notifier_id' => auth()->id(),
                        'type' => NotificationTypeEnum::CUSTOM,
                        'trigger' => $recipientParams['trigger'] ?? null,
                        'user_id' => $communicable->getUserId(),
                        'team_id' => $teamId,
                        'notification_template_id' => $this->id,
                        // TODO We need to see if this is needed in this new version
                        'about_id' => $recipientParams['about_id'] ?? null,
                        'about_type' => $recipientParams['about_type'] ?? null,
                        'custom_message' => ContentReplacer::setText($this->communication->content)->replace(CommunicationType::DATABASE),
                        'custom_button_text' => ContentReplacer::setText($this->custom_button_text)->replace(CommunicationType::DATABASE),
                        'custom_button_href' => ContentReplacer::setText($this->custom_button_href)->replace(CommunicationType::DATABASE),
                        'has_reminder_button' => $this->has_reminder_button,
                        'custom_button_handler' => $this->custom_button_handler,
                    ];

                    $delivered[$position] = true;
                }
            });
        }

        if ($notifications) {
            Notification::insert($notifications);
        }

        return array_keys($delivered);
    }
}
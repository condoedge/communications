<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Facades\ContentReplacer;
use Illuminate\Database\Eloquent\Model;
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
        'custom_button_href',
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
     * @param \Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function sendNotification(array $communicables, $params = [])
    {
        $notifications = [];

        foreach ($communicables as $communicable) {
            foreach ($params['teams_ids'] as $teamId) {
                if (!$teamId || !$communicable->hasTeam($teamId)) {
                    continue;
                }

                ContentReplacer::injectContext($params);
        
                $notifications[] = [
                    'notifier_id' => auth()->id(),
                    'type' => NotificationTypeEnum::CUSTOM,
                    'trigger' => $params['trigger'] ?? null,
                    'user_id' => $communicable->getId(),
                    'team_id' => $teamId,
                    'notification_template_id' => $this->id,
                    // TODO We need to see if this is needed in this new version
                    'about_id' => $params['about_id'] ?? null,
                    'about_type' => $params['about_type'] ?? null,
                    'custom_message' => ContentReplacer::setText($this->communication->content)->replace(CommunicationType::DATABASE),
                    'custom_button_text' => ContentReplacer::setText($this->custom_button_text)->replace(CommunicationType::DATABASE),
                    'custom_button_href' => ContentReplacer::setText($this->custom_button_href)->replace(CommunicationType::DATABASE),
                    'has_reminder_button' => $this->has_reminder_button,
                    'custom_button_handler' => $this->custom_button_handler,
                ];
            }
        }

        Notification::insert($notifications);    
    }
}
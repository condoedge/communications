<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Models\NotificationTemplate;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable;


class DatabaseCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return DatabaseCommunicable::class;
    }

    // NOTIFICATION
    /**
     * @param DatabaseCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $notificationTemplate = NotificationTemplate::forCommunication($this->communication->id)->first();

        $notificationTemplate?->sendNotification($communicables, $params);
    }

    // SAVING
    public function formInputs($trigger = null)
    {
        $notificationTemplate = NotificationTemplate::forCommunication($this->communication->id)->first();

        $attrs = collect($this->communication->getAttributes())->merge($notificationTemplate?->getAttributes())->toArray();

        return [
            _Rows(
                _EnhancedEditor('Content')->name('content', false)->default(json_decode($attrs['content'] ?? '{}'))
                    ->filterVarsToThisIds($trigger::validVariablesIds()),
            ),

            _EnhancedEditor('communications.button-label')->name('custom_button_text', false)->default(json_decode($attrs['custom_button_text'] ?? '{}'))
                ->filterVarsToThisIds($trigger::validVariablesIds('custom_button_text'))->toolbar([])->baseInputHeight(),
            _EnhancedEditor('communications.button-route')->name('custom_button_href', false)->default(json_decode($attrs['custom_button_href'] ?? '{}'))
                ->filterVarsToThisIds($trigger::validVariablesIds('custom_button_href'))->toolbar([])->baseInputHeight(),
            _Checkbox('communications.has-reminder-button')->name('has_reminder_button', false)->default($notificationTemplate?->has_reminder_button),
        ];
    }

    public function save($groupId = null, $attributes = [])
    {
        parent::save($groupId, $attributes);

        $notificationTemplate = NotificationTemplate::forCommunication($this->communication->id)->first() ?: new NotificationTemplate();
        $notificationTemplate->custom_button_text = $attributes['custom_button_text'] ?? null;
        $notificationTemplate->custom_button_href = $attributes['custom_button_href'] ?? null;
        $notificationTemplate->has_reminder_button = $attributes['has_reminder_button'] ?? null;
        $notificationTemplate->custom_button_handler = $attributes['custom_button_handler'] ?? null;

        $notificationTemplate->communication_id = $this->communication->id;
        $notificationTemplate->save();
    }

    // VALIDATION

    public function requiredAttributes()
    {
        return ['content', 'custom_button_text', 'custom_button_href'];
    }
}

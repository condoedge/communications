<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Models\NotificationTemplate;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable;


class DatabaseCommunicationHandler extends AbstractCommunicationHandler
{
    public function requiredAttributes()
    {
        return array_merge(parent::requiredAttributes(), ['custom_button_text', 'custom_button_href']);
    }

    public function formInputs()
    {
        $notificationTemplate = NotificationTemplate::forCommunication($this->communication->id)->first();

        return _Rows(
            _Rows(parent::formInputs()),
            
            _Translatable('button text')->name('custom_button_text', false)->default($notificationTemplate?->custom_button_text ?: []),
            _Translatable('button href')->name('custom_button_href', false)->default($notificationTemplate?->custom_button_href ?: []),
            _Checkbox('has reminder button')->name('has_reminder_button', false)->default($notificationTemplate?->has_reminder_button),
        );
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

    public function communicableInterface()
    {
        return DatabaseCommunicable::class;
    }

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
}
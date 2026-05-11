<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\EventsHandling\Contracts\DatabaseCommunicableEvent;
use Condoedge\Communications\Models\NotificationTemplate;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\DatabaseCommunicable;
use Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler;

class DatabaseCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return DatabaseCommunicable::class;
    }

    public function communicableEventInterface()
    {
        return DatabaseCommunicableEvent::class;
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
    public function formInputs($trigger = null, $context = [])
    {
        $notificationTemplate = NotificationTemplate::forCommunication($this->communication->id)->first();

        $attrs = collect($this->communication->getAttributes())->merge($notificationTemplate?->getAttributes())->toArray();

        $handlerOptions = $this->getValidHandlerOptions($trigger, $context);
        $currentHandler = $attrs['custom_button_handler'] ?? null;
        $usesDefaultHandler = $this->isDefaultHandler($currentHandler);

        return [
            _Rows(
                _EnhancedEditor('Content')->name('content', false)->default(json_decode($attrs['content'] ?? '{}'))
                    ->filterVarsToThisIds($trigger::validVariablesIds(context: $context)),
            ),

            _Rows(
                count($handlerOptions) <= 1 ? null :
                    _Select('communications.button-handler')->name('custom_button_handler', false)
                        ->options($handlerOptions)
                        ->class('!mb-0')
                        ->default($currentHandler ?? '')
                        ->toggleId('default-button-fields', !$usesDefaultHandler),

                _Rows(
                    _EnhancedEditor('communications.button-label')->name('custom_button_text', false)->default(json_decode($attrs['custom_button_text'] ?? '{}'))
                        ->filterVarsToThisIds($trigger::validVariablesIds('custom_button_text', context: $context))->toolbar([])->baseInputHeight(),

                    _Select('communications.button-route')->options($this->getAllValidRoutes($trigger))
                        ->class('!mb-0 mt-2')
                        ->name('custom_button_href', false)->default($this->getAllValidRoutes($trigger)->search($attrs['custom_button_href'] ?? null)),
                )->id('default-button-fields')->class('mt-4 gap-2'),
            )->class('mt-6 mb-4'),

            _Checkbox('communications.has-reminder-button')->name('has_reminder_button', false)->default($notificationTemplate?->has_reminder_button),
        ];
    }

    public function save($groupId = null, $attributes = [])
    {
        parent::save($groupId, $attributes);

        $handlerClass = $attributes['custom_button_handler'] ?? null;
        $usesDefaultHandler = $this->isDefaultHandler($handlerClass);

        $notificationTemplate = NotificationTemplate::forCommunication($this->communication->id)->first() ?: new NotificationTemplate();
        $notificationTemplate->custom_button_text = $usesDefaultHandler ? ($attributes['custom_button_text'] ?? null) : null;
        $notificationTemplate->custom_button_href = $usesDefaultHandler
            ? ($this->getAllValidRoutes($attributes['trigger'] ?? null)[$attributes['custom_button_href'] ?? null] ?? null)
            : null;
        $notificationTemplate->has_reminder_button = $attributes['has_reminder_button'] ?? null;
        $notificationTemplate->custom_button_handler = $handlerClass ?: null;

        $notificationTemplate->communication_id = $this->communication->id;
        $notificationTemplate->save();
    }

    /**
     * Resolve the handler dropdown options for this trigger.
     *
     * Reads the global registry from `kompo-communications.notification_button_handlers`
     * and intersects with the trigger's `validNotificationButtonHandlers()` if defined.
     * 
     * By default we don't show this option unless the trigger explicitly allows it, to avoid confusion with handlers that may not be designed for this trigger.
     */
    protected function getValidHandlerOptions($trigger, array $context = []): array
    {
        $allHandlers = config('kompo-communications.notification_button_handlers', []);

        if (empty($allHandlers)) {
            return [];
        }

        $allowed = null;
        if ($trigger && method_exists($trigger, 'validNotificationButtonHandlers')) {
            $allowed = $trigger::validNotificationButtonHandlers($context);
        }

        if ($allowed === null) {
            if (!array_key_exists(DefaultNotificationButtonHandler::class, $allHandlers)) {
                return $allHandlers;
            }
            return [DefaultNotificationButtonHandler::class => $allHandlers[DefaultNotificationButtonHandler::class]];
        }

        return collect($allHandlers)->only($allowed)
            ->mapWithKeys(fn ($label, $class) => [$class => __($label)])
            ->all();
    }

    protected function isDefaultHandler(?string $handlerClass): bool
    {
        if (!$handlerClass) {
            return true;
        }

        $default = config(
            'kompo-auth.notifications.default_notification_button_handler',
            \Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler::class
        );

        return $handlerClass === $default;
    }

    protected function getAllValidRoutes($trigger)
    {
        if (!$trigger) {
            return [];
        }

        return collect($trigger::getValidRoutes())->mapWithKeys(fn ($v, $k) => [urldecode($k) => $v]);
    }

    // VALIDATION

    public function requiredAttributes()
    {
        return ['content'];
    }
}

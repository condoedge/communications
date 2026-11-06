<?php

return [
    'triggers' => [
        \Condoedge\Communications\Triggers\ManualTrigger::class,
    ],

    'communicable-types' => [

    ],

    'notification_button_handlers' => [
        \Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler::class
            => 'communications.handler-default-single-button',
    ],

    'manual-trigger' => [
        'valid-variables' => [
            // 'App\Models\User' => ['user_first_name', 'user_last_name', 'user_email'],
        ],

        'valid-routes' => [
            // 'dashboard'
        ],
    ],

    'reminders' => [
        // ScheduledReminder classes that communications:send-reminders runs.
        'scheduled' => [],

        'default_hour' => 9,

        // Null uses config('app.timezone').
        'timezone' => null,

        'prune_after_days' => 400,
    ],
];
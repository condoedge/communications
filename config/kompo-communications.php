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

    // mergeConfigFrom merges only one level deep, so a host publishing this file REPLACES the whole
    // reminders array rather than merging into it. For a registry that is the right behaviour — a
    // host must be able to state exactly what runs, and a package that kept merging its own list
    // back in would re-arm a reminder the host deliberately removed.
    'reminders' => [
        // Registering a reminder is adding a class here. The once-only guarantee, the scheduling
        // and the dry run come from the runner.
        'scheduled' => [],

        // The hour a scope with no explicit send time sweeps at.
        'default_hour' => 9,

        // Null = config('app.timezone'). A per-record timezone is deliberately not supported.
        'timezone' => null,

        // Claims older than this are prunable. Long enough that a support question about last
        // season's reminders is still answerable, short enough that the table does not grow
        // forever.
        'prune_after_days' => 400,
    ],
];
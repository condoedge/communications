<?php

return [
    'triggers' => [
        \Condoedge\Communications\Triggers\ManualTrigger::class,
    ],

    'communicable-types' => [

    ],

    // The mailable class every email renders through unless the event passes its own `layout`.
    'email-layout' => \Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable::class,

    // Shows deliveries, opens, clicks and bounces in the statistics. For a host that records them.
    'engagement-stats' => false,

    // How the queue runs the job that sends an event's communications. Null leaves it to the worker.
    // Only an event that names its groups and declares an idempotency key is continued by a new
    // attempt; any other event interrupted midway is sent again in full.
    'queue' => [
        'tries' => null,
        'timeout' => null,
        'backoff' => null,
    ],

    'notification_button_handlers' => [
        \Kompo\Auth\Models\Monitoring\DefaultNotificationButtonHandler::class
            => 'communications.handler-default-single-button',
    ],

    /**
     * Consent withdrawal for non-transactional mail. The link is minted per recipient and injected
     * automatically; a host only needs to set `mailto` if it wants the RFC 2369 mailto: variant too.
     */
    'unsubscribe' => [
        'route_prefix' => 'communications',
        'mailto' => null,

        // The recipient's landing page. Subclass it to brand the page, and name the host's guest
        // layout so it renders inside the same shell as the rest of the signed-link pages.
        'component' => \Condoedge\Communications\Components\UnsubscribePage::class,
        'layout' => null,

        // CommunicationCategory values an unsubscribe silences. TRANSACTIONAL is never silenced.
        'categories' => ['notification', 'marketing'],
    ],

    // SuppressionRule classes every send runs, in order; the first one that objects decides.
    'suppression' => [
        'rules' => \Condoedge\Communications\Services\Suppression\SuppressionPipeline::DEFAULT_RULES,
    ],

    // Off, what an address chose to receive is not consulted and every send goes as if nothing was chosen.
    'address-preferences' => [
        'enabled' => true,
    ],

    'channels' => [
        // Editor only: templates that exist on a restricted channel are still sent.
        'restricted-editor' => ['push'],
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
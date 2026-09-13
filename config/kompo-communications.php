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
    ],

    'manual-trigger' => [
        'valid-variables' => [
            // 'App\Models\User' => ['user_first_name', 'user_last_name', 'user_email'],
        ],

        'valid-routes' => [
            // 'dashboard'
        ],
    ],
];
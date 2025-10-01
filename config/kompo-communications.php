<?php

return [
    'triggers' => [
        \Condoedge\Communications\Triggers\ManualTrigger::class,
    ],

    'communicable-types' => [

    ],

    'manual-trigger' => [
        'valid-variables' => [
            // 'App\Models\User' => ['user_first_name', 'user_last_name', 'user_email'],
        ],
    ],
];
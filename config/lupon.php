<?php

return [
    'timezone' => 'Asia/Manila',
    // Temporary review override; set false to restore scheduled-time outcome locks.
    'hearing_actions_unlocked' => env('LUPON_HEARING_ACTIONS_UNLOCKED', true),
    // Approved local templates may override the unsigned system-generated layouts.
    'templates' => [
        'notice_of_hearing' => env('LUPON_NOTICE_TEMPLATE'), 'summons' => env('LUPON_SUMMONS_TEMPLATE'),
        'amicable_settlement' => env('LUPON_SETTLEMENT_TEMPLATE'), 'cfa' => env('LUPON_CFA_TEMPLATE'),
    ],
    'holidays' => array_values(array_filter(array_map('trim', explode(',', env('LUPON_HOLIDAYS', ''))))),
];

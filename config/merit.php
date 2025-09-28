<?php

return [

    'achiever_model' => env('MERIT_ACHIEVER_MODEL', App\Models\User::class),
    'achievements_table' => env('MERIT_ACHIEVEMENTS_TABLE', 'achiever_achievements'),
    'cache_ttl' => 10,
    'queue_events' => env('MERIT_QUEUE_EVENTS', false),
];

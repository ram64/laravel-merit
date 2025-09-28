<?php

return [

    /**
     * The default Achiever model.
     * This model should use the Achiever trait.
     */
    'achiever_model' => env('MERIT_ACHIEVER_MODEL', \App\Models\User::class),
    /**
     * The table used to store achiever achievements.
     */
    'achievements_table' => env('MERIT_ACHIEVEMENTS_TABLE', 'achiever_achievements'),
    /**
     * Cache TTL in minutes for achievements.
     */
    'cache_ttl' => 10,
    /**
     * Whether to queue events or dispatch them synchronously.
     */
    'queue_achievements' => env('MERIT_QUEUE_EVENTS', default: false),
    'queue_connection' => env('MERIT_QUEUE_CONNECTION', null),

    'achievements_namespace' => 'App\\Achievements\\',
    'achievements_path' => app_path('Achievements'),

];

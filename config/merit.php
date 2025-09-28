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
    'cache_ttl' => env('MERIT_CACHE_TTL', 10),
    /**
     * Whether to queue events or dispatch them synchronously.
     */
    'queue_achievements' => env('MERIT_QUEUE_EVENTS', default: false),
    'queue_connection' => env('MERIT_QUEUE_CONNECTION', null),

    'achievements_namespace' => env('MERIT_ACHIEVEMENTS_NAMESPACE', 'App\\Achievements\\'),
    'achievements_path' => env('MERIT_ACHIEVEMENTS_PATH', 'app/Achievements'),

    /**
     * Debug settings for achievement tracking
     */
    'debug' => [
        'enabled' => env('MERIT_DEBUG', false),
        'log_channel' => env('MERIT_DEBUG_CHANNEL', 'single'),
        'log_level' => env('MERIT_DEBUG_LEVEL', 'debug'),
    ],

];

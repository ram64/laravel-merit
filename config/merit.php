<?php

return [

    /**
     * The default Achiever model.
     * This model should use the CanAchieve trait.
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

    /**
     * Namespace and path for achievement definitions. This is used for auto-discovery.
     */
    'achievements_namespace' => env('MERIT_ACHIEVEMENTS_NAMESPACE', 'App\\Definitions\\Achievements\\'),
    'achievements_path' => app_path(env('MERIT_ACHIEVEMENTS_PATH', 'Definitions/Achievements')),

    /**
     * Debug settings for achievement tracking
     */
    'debug' => [
        'enabled' => env('MERIT_DEBUG', false),
        'log_channel' => env('MERIT_DEBUG_CHANNEL', 'single'),
        'log_level' => env('MERIT_DEBUG_LEVEL', 'debug'),
    ],

    /**
     * Experience system configuration
     */
    'experience' => [
        /**
         * The table used to store experiencer data.
         * Models using the CanExperience trait will have their experience stored here.
         */
        'table' => env('MERIT_EXPERIENCE_TABLE', 'experiencers'),

        /**
         * The table used to store experience logs.
         */
        'logs_table' => env('MERIT_EXPERIENCE_LOGS_TABLE', 'experience_logs'),

        /**
         * Whether to enable experience logging.
         */
        'logging_enabled' => env('MERIT_EXPERIENCE_LOGGING', true),

        /**
         * Cache TTL in minutes for experience data.
         */
        'cache_ttl' => env('MERIT_EXPERIENCE_CACHE_TTL', 10),

        /**
         * Level calculation method: 'static' or 'dynamic'
         */
        'level_calculation' => env('MERIT_LEVEL_CALCULATION', 'static'),

        /**
         * Static level thresholds (only used when level_calculation is 'static')
         * Format: [level => required_experience]
         */
        'level_thresholds' => [
            1 => 0,
            2 => 100,
            3 => 250,
            4 => 500,
            5 => 1000,
            6 => 1750,
            7 => 2750,
            8 => 4000,
            9 => 5500,
            10 => 7500,
            // Add more levels as needed
        ],

        /**
         * Dynamic level formula (only used when level_calculation is 'dynamic')
         * Formula should return the experience required for a given level
         * Available variables: $level
         */
        'level_formula' => '50 * $level * $level', // Example: quadratic growth

        /**
         * Maximum level allowed in the system
         * Used for dynamic formulas and precalculation of level requirements
         */
        'max_level' => env('MERIT_MAX_LEVEL', 100),

        /**
         * Whether to allow level decreases through setLevel() method.
         * When false, setLevel() will only allow level increases.
         * When true, setLevel() can decrease levels for administrative purposes.
         */
        'allow_level_decrease' => env('MERIT_ALLOW_LEVEL_DECREASE', false),
    ],

];

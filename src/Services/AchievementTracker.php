<?php

namespace Ram64\LaravelMerit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Ram64\LaravelMerit\Definitions\Achievements\BaseAchievementDefinition;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementProgressed;
use Ram64\LaravelMerit\Events\AchievementStarted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Jobs\ProcessAchievementCheck;
use Ram64\LaravelMerit\Models\AchieverAchievement;

class AchievementTracker
{
    protected static $achievements = [];

    protected static $discovered = false;

    /**
     * Check all achievements for a given achiever
     */
    public static function check($achiever)
    {
        static::debug('Starting achievement check', [
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
            'queue_enabled' => config('merit.queue_achievements', true),
        ]);

        if (config('merit.queue_achievements', true)) {
            ProcessAchievementCheck::dispatch($achiever);
            static::debug('Achievement check dispatched to queue');
        } else {
            static::processCheck($achiever);
        }
    }

    /**
     * Process achievement check (called directly or via queue)
     */
    public static function processCheck($achiever)
    {
        $achievements = static::getAllAchievements();

        static::debug('Processing achievement check', [
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
            'total_achievements' => count($achievements),
        ]);

        // One query for the achiever's unlocked keys, rather than one exists()
        // per prerequisite per achievement. Unlocks made below are fed back in
        // so a chain still completes in this single pass.
        $opened = ! AchievementDefinition::hasUnlockedKeysWindow($achiever);

        if ($opened) {
            AchievementDefinition::primeUnlockedKeys($achiever);
        }

        try {
            static::processAchievements($achiever, $achievements);
        } finally {
            // Only close a window this call opened; a caller above may own one.
            if ($opened) {
                AchievementDefinition::flushUnlockedKeys($achiever);
            }
        }

        static::debug('Achievement check processing completed');
    }

    /**
     * The body of processCheck(), inside the prerequisite batch window.
     *
     * @param  array<int, class-string<BaseAchievementDefinition>>  $achievements
     */
    protected static function processAchievements($achiever, array $achievements): void
    {
        foreach ($achievements as $achievementClass) {
            $achievementKey = $achievementClass::key();

            static::debug("Checking achievement: {$achievementKey}", [
                'achievement_class' => $achievementClass,
                'achiever_id' => $achiever->getKey(),
            ]);

            // Skip if prerequisites not met
            if (! $achievementClass::canStart($achiever)) {
                static::debug("Prerequisites not met for achievement: {$achievementKey}");

                continue;
            }

            // Get current progress from achievement logic
            $currentProgress = $achievementClass::calculateCurrentProgress($achiever);

            static::debug('Current progress calculated', [
                'achievement' => $achievementKey,
                'progress' => $currentProgress,
                'goal' => $achievementClass::goal(),
            ]);

            // Skip if no progress at all - don't create records for 0 progress
            if ($currentProgress <= 0) {
                static::debug("No progress for achievement: {$achievementKey}");

                continue;
            }

            $record = AchieverAchievement::firstOrCreate(
                [
                    'achiever_id' => $achiever->getKey(),
                    'achiever_type' => $achiever->getMorphClass(),
                    'achievement_key' => $achievementClass::key(),
                ],
                [
                    'progress' => $currentProgress,
                    'goal' => $achievementClass::goal(),
                    'started_at' => now(),
                ]
            );

            // Fire started event if this is the first time
            if ($record->wasRecentlyCreated) {
                static::debug("Achievement started: {$achievementKey}");
                event(new AchievementStarted($record, $achiever));
            }

            // Skip if already unlocked
            if ($record->unlocked_at !== null) {
                static::debug("Achievement already unlocked: {$achievementKey}");

                continue;
            }

            $oldProgress = $record->progress;
            $record->progress = min($currentProgress, $record->goal);

            // Fire progress event if progress changed
            if ($record->progress > $oldProgress) {
                static::debug('Achievement progress updated', [
                    'achievement' => $achievementKey,
                    'old_progress' => $oldProgress,
                    'new_progress' => $record->progress,
                    'goal' => $record->goal,
                ]);
                event(new AchievementProgressed($record, $achiever, $record->progress));
            }

            // Check if completed
            $wasCompleted = $record->completed_at !== null;
            if ($record->progress >= $record->goal && ! $wasCompleted) {
                static::debug("Achievement completed and unlocked: {$achievementKey}");
                $record->completed_at = now();
                event(new AchievementCompleted($record, $achiever));

                // Auto-unlock when completed
                $record->unlocked_at = now();
                AchievementDefinition::noteUnlocked($achiever, $achievementKey);
                event(new AchievementUnlocked($record, $achiever));
            }

            $record->save();

            // Clear the caches once the new state is actually persisted, so a
            // listener that read the record on the way through cannot leave a
            // pre-save value behind. Any stored change counts: a new row and a
            // fall in progress leave a cached read just as stale as a rise.
            if ($record->wasRecentlyCreated || $record->wasChanged()) {
                static::forgetAchievementCaches($achiever, $achievementKey);
            }
        }
    }

    /**
     * Manually award an achievement to an achiever
     */
    public static function award($achiever, string $achievementKey)
    {
        static::debug('Manually awarding achievement', [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
        ]);

        $achievementClass = static::getAchievement($achievementKey);

        if (! $achievementClass) {
            static::debug("Achievement not found: {$achievementKey}");
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        $record = AchieverAchievement::firstOrCreate(
            [
                'achiever_id' => $achiever->getKey(),
                'achiever_type' => $achiever->getMorphClass(),
                'achievement_key' => $achievementKey,
            ],
            [
                'progress' => 0,
                'goal' => $achievementClass::goal(),
                'started_at' => now(),
            ]
        );

        // Fire started event if this is the first time
        if ($record->wasRecentlyCreated) {
            event(new AchievementStarted($record, $achiever));
        }

        // Skip if already unlocked
        if ($record->unlocked_at !== null) {
            return $record;
        }

        // Set progress to goal and mark as completed/unlocked
        $oldProgress = $record->progress;
        $record->progress = $record->goal;

        // Fire progress event if progress changed
        if ($record->progress > $oldProgress) {
            event(new AchievementProgressed($record, $achiever, $record->progress));
        }

        // Mark as completed if not already
        if ($record->completed_at === null) {
            $record->completed_at = now();
            event(new AchievementCompleted($record, $achiever));
        }

        // Mark as unlocked
        $record->unlocked_at = now();
        $record->save();

        AchievementDefinition::noteUnlocked($achiever, $achievementKey);
        event(new AchievementUnlocked($record, $achiever));

        // Clear cache when manually awarded
        static::forgetAchievementCaches($achiever, $achievementKey);

        return $record;
    }

    /**
     * Increment progress towards an achievement
     */
    public static function increment($achiever, string $achievementKey, int $amount = 1)
    {
        static::debug('Incrementing achievement progress', [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->getKey(),
            'amount' => $amount,
        ]);

        $achievementClass = static::getAchievement($achievementKey);

        if (! $achievementClass) {
            static::debug("Achievement not found: {$achievementKey}");
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        // Check if prerequisites are met
        if (! $achievementClass::canStart($achiever)) {
            static::debug("Prerequisites not met for increment: {$achievementKey}");

            return;
        }

        $record = AchieverAchievement::firstOrCreate(
            [
                'achiever_id' => $achiever->getKey(),
                'achiever_type' => $achiever->getMorphClass(),
                'achievement_key' => $achievementKey,
            ],
            [
                'progress' => 0,
                'goal' => $achievementClass::goal(),
                'started_at' => now(),
            ]
        );

        // Fire started event if this is the first time
        if ($record->wasRecentlyCreated) {
            event(new AchievementStarted($record, $achiever));
        }

        // Skip if already unlocked
        if ($record->unlocked_at !== null) {
            return $record;
        }

        $oldProgress = $record->progress;
        $record->progress = min($record->progress + $amount, $record->goal);

        // Fire progress event if progress changed
        if ($record->progress > $oldProgress) {
            event(new AchievementProgressed($record, $achiever, $record->progress));
        }

        // Check if completed
        $wasCompleted = $record->completed_at !== null;
        if ($record->progress >= $record->goal && ! $wasCompleted) {
            $record->completed_at = now();
            event(new AchievementCompleted($record, $achiever));

            // Auto-unlock when completed
            $record->unlocked_at = now();
            AchievementDefinition::noteUnlocked($achiever, $achievementKey);
            event(new AchievementUnlocked($record, $achiever));
        }

        $record->save();

        // Clear cache whenever the stored row changed, a negative amount included
        if ($record->wasRecentlyCreated || $record->wasChanged()) {
            static::forgetAchievementCaches($achiever, $achievementKey);
        }

        return $record;
    }

    /**
     * Register an achievement class
     */
    public static function register(string $achievementClass)
    {
        static::$achievements[$achievementClass::key()] = $achievementClass;
    }

    /**
     * Get a specific achievement class by key
     */
    public static function getAchievement(string $key): ?string
    {
        static::discoverAchievements();

        return static::$achievements[$key] ?? null;
    }

    /**
     * Get a specific achievement definition instance by key
     */
    public static function getDefinition(string $key): ?object
    {
        $className = static::getAchievement($key);

        if (! $className) {
            return null;
        }

        return new $className;
    }

    /**
     * Get the achievement definition from an AchieverAchievement model
     */
    public static function getDefinitionFromAchievement(AchieverAchievement $achievement): ?object
    {
        return static::getDefinition($achievement->achievement_key);
    }

    /**
     * Get all registered achievement classes
     */
    public static function getAllAchievements(): array
    {
        static::discoverAchievements();

        return static::sortAchievementsTopologically(array_values(static::$achievements));
    }

    /**
     * Sort achievements in topological order based on prerequisites
     */
    protected static function sortAchievementsTopologically(array $achievements): array
    {
        if (empty($achievements)) {
            return $achievements;
        }

        // Build dependency graph: achievement => [prerequisites]
        $graph = [];
        $inDegree = [];
        $keyToClass = [];

        foreach ($achievements as $achievementClass) {
            $key = $achievementClass::key();
            $keyToClass[$key] = $achievementClass;
            $graph[$key] = [];
            $inDegree[$key] = 0;
        }

        // Build the graph and in-degrees
        foreach ($achievements as $achievementClass) {
            $key = $achievementClass::key();
            $prerequisites = $achievementClass::prerequisite();

            if (! $prerequisites) {
                continue;
            }

            if (is_string($prerequisites)) {
                $prerequisites = [$prerequisites];
            }

            foreach ($prerequisites as $prerequisite) {
                // Extract prerequisite key
                $prereqKey = static::extractAchievementKey($prerequisite);

                // Only add edge if prerequisite exists in our achievements
                if (isset($graph[$prereqKey])) {
                    $graph[$prereqKey][] = $key; // prereq points to dependent
                    $inDegree[$key]++; // dependent has one more prerequisite
                }
            }
        }

        // Kahn's algorithm for topological sort
        $queue = [];
        $result = [];

        // Start with achievements that have no prerequisites (in-degree 0)
        foreach ($inDegree as $key => $degree) {
            if ($degree === 0) {
                $queue[] = $key;
            }
        }

        while (! empty($queue)) {
            $current = array_shift($queue);
            $result[] = $keyToClass[$current];

            // For each achievement that depends on current
            foreach ($graph[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Check for cycles (shouldn't happen with proper prerequisites)
        if (count($result) !== count($achievements)) {
            static::debug('Cycle detected in achievement prerequisites, falling back to unsorted order', [
                'total_achievements' => count($achievements),
                'sorted_count' => count($result),
            ]);

            return $achievements; // Fallback to original order
        }

        static::debug('Achievements sorted topologically', [
            'total' => count($result),
            'order' => array_map(fn ($class) => $class::key(), $result),
        ]);

        return $result;
    }

    /**
     * Extract achievement key from prerequisite (string or class)
     */
    protected static function extractAchievementKey(string $prerequisite): string
    {
        // If it's a class name (contains backslashes or is a valid class)
        if (str_contains($prerequisite, '\\') || class_exists($prerequisite)) {
            // Check if it's a valid AchievementDefinition class
            if (
                class_exists($prerequisite) &&
                method_exists($prerequisite, 'key')
            ) {
                return $prerequisite::key();
            }
        }

        // Otherwise, treat it as a direct key
        return $prerequisite;
    }

    /**
     * Get all achievement keys
     */
    public static function getAchievementKeys(): array
    {
        static::discoverAchievements();

        return array_keys(static::$achievements);
    }

    /**
     * Discover achievements from the configured directory
     */
    protected static function discoverAchievements()
    {
        if (static::$discovered) {
            return;
        }

        $cacheKey = static::getDiscoveryCacheKey();
        $cachedAchievements = Cache::get($cacheKey);

        // A definition deleted or renamed since the list was cached would be a
        // fatal "class not found" on first use, for every check, until the
        // entry expired. A list naming a missing class is rediscovered instead.
        if (is_array($cachedAchievements) && static::allClassesExist($cachedAchievements)) {
            static::$achievements = $cachedAchievements;
            static::$discovered = true;
            static::debug('Loaded achievements from cache', ['count' => count($cachedAchievements)]);

            return;
        }

        $achievementPath = config('merit.achievements_path', app_path('Achievements'));
        $achievementNamespace = config('merit.achievements_namespace', 'App\\Achievements\\');

        static::debug('Starting achievement discovery', [
            'path' => $achievementPath,
            'namespace' => $achievementNamespace,
        ]);

        if (! File::exists($achievementPath)) {
            static::debug('Achievement path does not exist', ['path' => $achievementPath]);
            static::$discovered = true;

            return;
        }

        $files = File::allFiles($achievementPath);
        static::debug('Found files in achievement directory', ['count' => count($files)]);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $achievementNamespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);

                // Skip abstract classes and classes that don't extend our base
                if (
                    $reflection->isAbstract() ||
                    ! $reflection->isSubclassOf(BaseAchievementDefinition::class)
                ) {
                    static::debug('Skipping class (abstract or invalid base)', ['class' => $className]);

                    continue;
                }

                static::debug('Registering achievement class', ['class' => $className]);
                static::register($className);
            } else {
                static::debug('Class does not exist', ['class' => $className]);
            }
        }

        // Cache the discovered achievements
        Cache::put($cacheKey, static::$achievements, static::getCacheTtl());

        static::debug('Achievement discovery completed and cached', [
            'registered' => count(static::$achievements),
            'cache_ttl_seconds' => static::getCacheTtl(),
        ]);
        static::$discovered = true;
    }

    /**
     * Whether every class in a cached discovery list can still be loaded
     *
     * @param  array<string, string>  $achievementClasses
     */
    protected static function allClassesExist(array $achievementClasses): bool
    {
        foreach ($achievementClasses as $achievementClass) {
            if (! class_exists($achievementClass)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all achievement classes visible to the achiever
     * This excludes secret achievements that haven't been unlocked
     */
    public static function getVisibleAchievements($achiever): array
    {
        $allAchievements = static::getAllAchievements();

        return array_filter($allAchievements, function ($achievementClass) use ($achiever) {
            return $achievementClass::isVisibleTo($achiever);
        });
    }

    /**
     * Get the display name and description for many achievements at once.
     *
     * Rendering a list is the commonest thing an achievements package is asked
     * to do, and doing it through getDisplayName()/getDisplayDescription() is
     * two cache round trips per achievement — on the database cache driver,
     * two SELECTs each. This reads every name and description in one.
     *
     * Unknown keys are omitted rather than returned as nulls.
     *
     * @param  array<int, string>  $achievementKeys
     * @return array<string, array{name: string, description: string}>
     */
    public static function getDisplayTextMany(Model $achiever, array $achievementKeys): array
    {
        $definitions = [];

        foreach (array_unique($achievementKeys) as $achievementKey) {
            $achievementClass = static::getAchievement($achievementKey);

            if ($achievementClass) {
                $definitions[$achievementKey] = $achievementClass;
            }
        }

        if ($definitions === []) {
            return [];
        }

        // Every key up front, so the hits cost one round trip between them.
        $cacheKeys = [];

        foreach ($definitions as $achievementKey => $achievementClass) {
            $cacheKeys[] = static::getNameCacheKey($achiever, $achievementKey);
            $cacheKeys[] = static::getDescriptionCacheKey($achiever, $achievementKey);
        }

        $cached = Cache::many($cacheKeys);

        // The secret gate asks whether the achiever has unlocked each secret
        // achievement, which is a lookup apiece and would undo the batching on
        // any page carrying a few of them. One window covers the lot. Pages
        // with no secrets on them — the common case — skip it entirely.
        $needsGate = false;

        foreach ($definitions as $achievementClass) {
            if ($achievementClass::isSecret()) {
                $needsGate = true;

                break;
            }
        }

        $opened = $needsGate && ! AchievementDefinition::hasUnlockedKeysWindow($achiever);

        if ($opened) {
            AchievementDefinition::primeUnlockedKeys($achiever);
        }

        try {
            return static::resolveDisplayText($achiever, $definitions, $cached, count($achievementKeys));
        } finally {
            // Only close a window this call opened; a caller above may own one.
            if ($opened) {
                AchievementDefinition::flushUnlockedKeys($achiever);
            }
        }
    }

    /**
     * Pair the cached text with the definitions, filling in whatever missed.
     *
     * @param  array<string, class-string<BaseAchievementDefinition>>  $definitions
     * @param  array<string, mixed>  $cached
     * @return array<string, array{name: string, description: string}>
     */
    protected static function resolveDisplayText(Model $achiever, array $definitions, array $cached, int $requested): array
    {
        $display = [];
        $missed = 0;

        foreach ($definitions as $achievementKey => $achievementClass) {
            $name = $cached[static::getNameCacheKey($achiever, $achievementKey)] ?? null;
            $description = $cached[static::getDescriptionCacheKey($achiever, $achievementKey)] ?? null;

            if ($name === null || $description === null) {
                // Cold path. Hand the whole job back to the definition rather
                // than reproducing it, so an overridden accessor still wins and
                // the two paths cannot drift apart.
                $display[$achievementKey] = [
                    'name' => $achievementClass::getDisplayName($achiever),
                    'description' => $achievementClass::getDisplayDescription($achiever),
                ];
                $missed++;

                continue;
            }

            // Only real text is ever cached, so the secret gate has to be
            // re-applied here. Reading these keys and skipping it is how a
            // secret achievement's name leaks.
            if ($achievementClass::isTextHiddenFrom($achiever)) {
                $name = $achievementClass::hiddenName();
                $description = $achievementClass::hiddenDescription();
            }

            $display[$achievementKey] = [
                'name' => $name,
                'description' => $description,
            ];
        }

        static::debug('Batch display text read', [
            'achiever_id' => $achiever->getKey(),
            'requested' => $requested,
            'resolved' => count($display),
            'cold' => $missed,
        ]);

        return $display;
    }

    /**
     * Get achievement data with appropriate display names/descriptions
     */
    public static function getAchievementDisplay($achiever, string $achievementKey): array
    {
        $cacheKey = static::getDisplayCacheKey($achiever, $achievementKey);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            static::debug('Retrieved achievement display from cache', [
                'achievement' => $achievementKey,
                'achiever_id' => $achiever->getKey(),
            ]);

            return $cached;
        }

        $achievementClass = static::getAchievement($achievementKey);

        if (! $achievementClass) {
            return [];
        }

        $displayData = [
            'key' => $achievementClass::key(),
            'name' => $achievementClass::getDisplayName($achiever),
            'description' => $achievementClass::getDisplayDescription($achiever),
            'goal' => $achievementClass::goal(),
            'progress' => $achievementClass::getProgress($achiever),
            'progress_percentage' => $achievementClass::getProgressPercentage($achiever),
            'is_completed' => $achievementClass::isCompleted($achiever),
            'is_unlocked' => $achievementClass::isUnlocked($achiever),
            'is_started' => $achievementClass::isStarted($achiever),
            'is_secret' => $achievementClass::isSecret(),
            'is_visible' => $achievementClass::isVisibleTo($achiever),
            'category' => $achievementClass::category(),
        ];

        // Cache for shorter duration if not unlocked (progress may change)
        $cacheDuration = $displayData['is_unlocked'] ? static::getCacheTtl() : static::getCacheTtl() / 2;
        Cache::put($cacheKey, $displayData, $cacheDuration);

        static::debug('Cached achievement display data', [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->getKey(),
            'cache_duration' => $cacheDuration,
        ]);

        return $displayData;
    }

    /**
     * Reset discovery (useful for testing)
     */
    public static function resetDiscovery()
    {
        static::$achievements = [];
        static::$discovered = false;
    }

    /**
     * Log debug information if debug mode is enabled
     */
    protected static function debug(string $message, array $context = []): void
    {
        if (! config('merit.debug.enabled', false)) {
            return;
        }

        $channel = config('merit.debug.log_channel', 'single');
        $level = config('merit.debug.log_level', 'debug');

        Log::channel($channel)->log($level, "[Merit] {$message}", $context);
    }

    /**
     * Get cache TTL in seconds
     */
    protected static function getCacheTtl(): int
    {
        return config('merit.cache_ttl', 10) * 60; // Convert minutes to seconds
    }

    /**
     * Generate cache key for achievements discovery
     */
    protected static function getDiscoveryCacheKey(): string
    {
        $path = config('merit.achievements_path', app_path('Achievements'));
        $namespace = config('merit.achievements_namespace', 'App\\Achievements\\');

        return 'merit:achievements:discovery:'.md5($path.$namespace);
    }

    /**
     * Generate cache key for user achievements
     */
    protected static function getUserCacheKey($achiever): string
    {
        return 'merit:user:'.get_class($achiever).':'.$achiever->getKey();
    }

    /**
     * Generate cache key for achievement display
     */
    protected static function getDisplayCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:display:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Generate cache key for achievement name
     */
    protected static function getNameCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:name:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Generate cache key for achievement description
     */
    protected static function getDescriptionCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:description:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Generate cache key for a stored achievement record
     *
     * Must match BaseAchievementDefinition::getAchievementRecord().
     */
    protected static function getRecordCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:record:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Drop every per-achievement cache for one achiever
     */
    protected static function forgetAchievementCaches($achiever, string $achievementKey): void
    {
        Cache::forget(static::getDisplayCacheKey($achiever, $achievementKey));
        Cache::forget(static::getNameCacheKey($achiever, $achievementKey));
        Cache::forget(static::getDescriptionCacheKey($achiever, $achievementKey));
        Cache::forget(static::getRecordCacheKey($achiever, $achievementKey));
    }

    /**
     * Clear all caches related to an achiever
     */
    public static function clearUserCache($achiever): void
    {
        $userCacheKey = static::getUserCacheKey($achiever);
        Cache::forget($userCacheKey);

        // Clear display, name, description and record caches for all achievements
        $achievements = static::getAllAchievements();
        foreach ($achievements as $achievementClass) {
            static::forgetAchievementCaches($achiever, $achievementClass::key());
        }

        static::debug('Cleared user achievement caches', [
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
        ]);
    }

    /**
     * Clear discovery cache
     */
    public static function clearDiscoveryCache(): void
    {
        Cache::forget(static::getDiscoveryCacheKey());
        static::$discovered = false;
        static::$achievements = [];

        static::debug('Cleared achievement discovery cache');
    }
}

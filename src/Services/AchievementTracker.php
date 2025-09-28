<?php

namespace Ram64\LaravelMerit\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
            'achiever_id' => $achiever->id,
            'achiever_type' => get_class($achiever),
            'queue_enabled' => config('merit.queue_achievements', true)
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
            'achiever_id' => $achiever->id,
            'achiever_type' => get_class($achiever),
            'total_achievements' => count($achievements)
        ]);

        foreach ($achievements as $achievementClass) {
            $achievementKey = $achievementClass::key();

            static::debug("Checking achievement: {$achievementKey}", [
                'achievement_class' => $achievementClass,
                'achiever_id' => $achiever->id
            ]);

            // Skip if prerequisites not met
            if (!$achievementClass::canStart($achiever)) {
                static::debug("Prerequisites not met for achievement: {$achievementKey}");
                continue;
            }

            // Get current progress from achievement logic
            $currentProgress = $achievementClass::calculateCurrentProgress($achiever);

            static::debug("Current progress calculated", [
                'achievement' => $achievementKey,
                'progress' => $currentProgress,
                'goal' => $achievementClass::goal()
            ]);

            $record = AchieverAchievement::firstOrCreate(
                [
                    'achiever_id' => $achiever->id,
                    'achiever_type' => get_class($achiever),
                    'achievement_key' => $achievementClass::key(),
                ],
                [
                    'progress' => 1,
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
                static::debug("Achievement progress updated", [
                    'achievement' => $achievementKey,
                    'old_progress' => $oldProgress,
                    'new_progress' => $record->progress,
                    'goal' => $record->goal
                ]);
                event(new AchievementProgressed($record, $achiever, $record->progress, $record->goal));
            }

            // Check if completed
            $wasCompleted = $record->completed_at !== null;
            if ($record->progress >= $record->goal && !$wasCompleted) {
                static::debug("Achievement completed and unlocked: {$achievementKey}");
                $record->completed_at = now();
                event(new AchievementCompleted($record, $achiever));

                // Auto-unlock when completed
                $record->unlocked_at = now();
                event(new AchievementUnlocked($record, $achiever));
            }

            $record->save();
        }

        static::debug('Achievement check processing completed');
    }

    /**
     * Manually award an achievement to an achiever
     */
    public static function award($achiever, string $achievementKey)
    {
        static::debug("Manually awarding achievement", [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->id,
            'achiever_type' => get_class($achiever)
        ]);

        $achievementClass = static::getAchievement($achievementKey);

        if (!$achievementClass) {
            static::debug("Achievement not found: {$achievementKey}");
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        $record = AchieverAchievement::firstOrCreate(
            [
                'achiever_id' => $achiever->id,
                'achiever_type' => get_class($achiever),
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
            event(new AchievementProgressed($record, $achiever, $record->progress, $record->goal));
        }

        // Mark as completed if not already
        if ($record->completed_at === null) {
            $record->completed_at = now();
            event(new AchievementCompleted($record, $achiever));
        }

        // Mark as unlocked
        $record->unlocked_at = now();
        $record->save();

        event(new AchievementUnlocked($record, $achiever));

        return $record;
    }

    /**
     * Increment progress towards an achievement
     */
    public static function increment($achiever, string $achievementKey, int $amount = 1)
    {
        static::debug("Incrementing achievement progress", [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->id,
            'amount' => $amount
        ]);

        $achievementClass = static::getAchievement($achievementKey);

        if (!$achievementClass) {
            static::debug("Achievement not found: {$achievementKey}");
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        // Check if prerequisites are met
        if (!$achievementClass::canStart($achiever)) {
            static::debug("Prerequisites not met for increment: {$achievementKey}");
            return;
        }

        $record = AchieverAchievement::firstOrCreate(
            [
                'achiever_id' => $achiever->id,
                'achiever_type' => get_class($achiever),
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
            event(new AchievementProgressed($record, $achiever, $record->progress, $record->goal));
        }

        // Check if completed
        $wasCompleted = $record->completed_at !== null;
        if ($record->progress >= $record->goal && !$wasCompleted) {
            $record->completed_at = now();
            event(new AchievementCompleted($record, $achiever));

            // Auto-unlock when completed
            $record->unlocked_at = now();
            event(new AchievementUnlocked($record, $achiever));
        }

        $record->save();

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
     * Get all registered achievement classes
     */
    public static function getAllAchievements(): array
    {
        static::discoverAchievements();

        return array_values(static::$achievements);
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

        $achievementPath = config('merit.achievements_path', app_path('Achievements'));
        $achievementNamespace = config('merit.achievements_namespace', 'App\\Achievements\\');

        static::debug('Starting achievement discovery', [
            'path' => $achievementPath,
            'namespace' => $achievementNamespace
        ]);

        if (!File::exists($achievementPath)) {
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

            $className = $achievementNamespace . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);

                // Skip abstract classes and classes that don't extend our base
                if (
                    $reflection->isAbstract() ||
                    !$reflection->isSubclassOf(\Ram64\LaravelMerit\Achievements\BaseAchievementDefinition::class)
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

        static::debug('Achievement discovery completed', ['registered' => count(static::$achievements)]);
        static::$discovered = true;
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
     * Get achievement data with appropriate display names/descriptions
     */
    public static function getAchievementDisplay($achiever, string $achievementKey): array
    {
        $achievementClass = static::getAchievement($achievementKey);

        if (!$achievementClass) {
            return [];
        }

        return [
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
            'category' => method_exists($achievementClass, 'category') ? $achievementClass::category() : 'general',
        ];
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
        if (!config('merit.debug.enabled', false)) {
            return;
        }

        $channel = config('merit.debug.log_channel', 'single');
        $level = config('merit.debug.log_level', 'debug');

        Log::channel($channel)->log($level, "[Merit] {$message}", $context);
    }
}

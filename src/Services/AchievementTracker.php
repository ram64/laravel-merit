<?php

namespace Ram64\LaravelMerit\Services;

use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Events\{AchievementStarted, AchievementProgressed, AchievementCompleted, AchievementUnlocked};
use Ram64\LaravelMerit\Jobs\ProcessAchievementCheck;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AchievementTracker
{
    protected static $achievements = [];
    protected static $discovered = false;

    /**
     * Check all achievements for a given achiever
     */
    public static function check($achiever)
    {
        if (config('merit.queue_achievements', true)) {
            ProcessAchievementCheck::dispatch($achiever);
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

        foreach ($achievements as $achievementClass) {
            // Skip if prerequisites not met
            if (!$achievementClass::canStart($achiever)) {
                continue;
            }

            // Skip if already unlocked
            if ($achievementClass::isUnlocked($achiever)) {
                continue;
            }

            // Check if qualifies
            if ($achievementClass::qualifiesFor($achiever)) {
                static::award($achiever, $achievementClass::key());
            }
        }
    }

    /**
     * Manually award an achievement to an achiever
     */
    public static function award($achiever, string $achievementKey)
    {
        $achievementClass = static::getAchievement($achievementKey);

        if (!$achievementClass) {
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
        $achievementClass = static::getAchievement($achievementKey);

        if (!$achievementClass) {
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        // Check if prerequisites are met
        if (!$achievementClass::canStart($achiever)) {
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

        if (!File::exists($achievementPath)) {
            static::$discovered = true;
            return;
        }

        $achievementNamespace = config('merit.achievements_namespace', 'App\\Achievements\\');

        $files = File::allFiles($achievementPath);

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
                    continue;
                }

                static::register($className);
            }
        }

        static::$discovered = true;
    }

    /**
     * Reset discovery (useful for testing)
     */
    public static function resetDiscovery()
    {
        static::$achievements = [];
        static::$discovered = false;
    }
}

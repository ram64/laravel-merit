<?php

namespace Ram64\LaravelMerit\Definitions\Achievements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Models\AchieverAchievement;

/**
 * Base class for all Achievements.
 * Implement the abstract methods to define specific achievements.
 */
abstract class BaseAchievementDefinition
{
    /**
     * Unique identifier for the achievement
     */
    abstract public static function key(): string;

    /**
     * Display name for the achievement
     */
    abstract public static function name(?Model $achiever = null): string;

    /**
     * Get cached display name for the achievement
     */
    public static function getCachedName(?Model $achiever = null): string
    {
        if ($achiever === null) {
            return static::name(null);
        }

        $cacheKey = 'merit:name:'.get_class($achiever).':'.$achiever->getKey().':'.static::key();

        return Cache::remember($cacheKey, config('merit.cache_ttl', 10) * 60, function () use ($achiever) {
            return static::name($achiever);
        });
    }

    /**
     * Description of what needs to be done
     */
    abstract public static function description(?Model $achiever = null): string;

    /**
     * Get cached display description for the achievement
     */
    public static function getCachedDescription(?Model $achiever = null): string
    {
        if ($achiever === null) {
            return static::description(null);
        }

        $cacheKey = 'merit:description:'.get_class($achiever).':'.$achiever->getKey().':'.static::key();

        return Cache::remember($cacheKey, config('merit.cache_ttl', 10) * 60, function () use ($achiever) {
            return static::description($achiever);
        });
    }

    /**
     * The target number to reach for completion
     */
    abstract public static function goal(): int;

    /**
     * Whether this achievement is secret (hidden until unlocked)
     */
    public static function isSecret(): bool
    {
        return false;
    }

    /**
     * Check if the achiever qualifies for this achievement
     */
    abstract public static function qualifiesFor(Model $achiever): bool;

    /**
     * Calculate current progress based on achiever's actual data
     * This should return the current count/progress towards the goal
     */
    public static function calculateCurrentProgress(Model $achiever): int
    {
        // Default implementation: if qualifies, return goal, otherwise 0
        return static::qualifiesFor($achiever) ? static::goal() : 0;
    }

    /**
     * Get current progress for this achievement
     */
    public static function getProgress(Model $achiever): int
    {
        $record = static::getAchievementRecord($achiever);

        return $record ? $record->progress : 0;
    }

    /**
     * Check if achievement is completed (goal reached)
     */
    public static function isCompleted(Model $achiever): bool
    {
        return static::getProgress($achiever) >= static::goal();
    }

    /**
     * Check if achievement is unlocked (awarded to user)
     */
    public static function isUnlocked(Model $achiever): bool
    {
        $record = static::getAchievementRecord($achiever);

        return $record ? ($record->unlocked_at ? true : false) : false;
    }

    /**
     * Check if achievement has been started
     */
    public static function isStarted(Model $achiever): bool
    {
        return static::getAchievementRecord($achiever) !== null;
    }

    /**
     * Get the achievement record for this achiever
     */
    public static function getAchievementRecord(Model $achiever): ?AchieverAchievement
    {
        $cacheKey = 'merit:record:'.get_class($achiever).':'.$achiever->getKey().':'.static::key();

        return Cache::remember($cacheKey, config('merit.cache_ttl', 10) * 60, function () use ($achiever) {
            return AchieverAchievement::where('achiever_id', $achiever->getKey())
                ->where('achiever_type', get_class($achiever))
                ->where('achievement_key', static::key())
                ->first();
        });
    }

    /**
     * Get achievement progress as a percentage
     */
    public static function getProgressPercentage(Model $achiever): float
    {
        $goal = static::goal();
        if ($goal === 0) {
            return 100.0;
        }

        return min(100.0, (static::getProgress($achiever) / $goal) * 100);
    }

    /**
     * Get remaining progress needed to complete
     */
    public static function getRemainingProgress(Model $achiever): int
    {
        return max(0, static::goal() - static::getProgress($achiever));
    }

    /**
     * Check if this achievement should be visible to the achiever
     * Secret achievements are only visible once unlocked
     */
    public static function isVisibleTo(Model $achiever): bool
    {
        if (! static::isSecret()) {
            return true;
        }

        return static::isUnlocked($achiever);
    }

    /**
     * The name shown in place of a secret achievement's own
     */
    public static function hiddenName(): string
    {
        return 'Hidden Achievement';
    }

    /**
     * The description shown in place of a secret achievement's own
     */
    public static function hiddenDescription(): string
    {
        return 'Complete certain actions to unlock this secret achievement.';
    }

    /**
     * Whether the achiever should be shown the placeholder text rather than
     * the real thing.
     *
     * The gate sits in front of the cache, and only real text is ever cached,
     * so anything reading those keys directly has to re-apply this.
     */
    public static function isTextHiddenFrom(Model $achiever): bool
    {
        return static::isSecret() && ! static::isUnlocked($achiever);
    }

    /**
     * Get the display name, hiding it if secret and not unlocked
     */
    public static function getDisplayName(Model $achiever): string
    {
        if (static::isTextHiddenFrom($achiever)) {
            return static::hiddenName();
        }

        return static::getCachedName($achiever);
    }

    /**
     * Get the display description, hiding it if secret and not unlocked
     */
    public static function getDisplayDescription(Model $achiever): string
    {
        if (static::isTextHiddenFrom($achiever)) {
            return static::hiddenDescription();
        }

        return static::getCachedDescription($achiever);
    }
}

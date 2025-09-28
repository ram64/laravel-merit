<?php

namespace Ram64\LaravelMerit\Achievements;

use Illuminate\Database\Eloquent\Model;
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
    abstract public static function name(): string;

    /**
     * Description of what needs to be done
     */
    abstract public static function description(): string;

    /**
     * The target number to reach for completion
     */
    abstract public static function goal(): int;

    /**
     * Check if the achiever qualifies for this achievement
     */
    abstract public static function qualifiesFor(Model $achiever): bool;

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
        return $record ? $record->is_unlocked : false;
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
        return AchieverAchievement::where('achiever_id', $achiever->id)
            ->where('achiever_type', get_class($achiever))
            ->where('achievement_key', static::key())
            ->first();
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
     * Return the key of the previous achievement in a chain
     */
    public static function previous(): ?string
    {
        return null; // default: no dependency
    }
}

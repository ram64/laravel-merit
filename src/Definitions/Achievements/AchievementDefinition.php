<?php

namespace Ram64\LaravelMerit\Definitions\Achievements;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Models\AchieverAchievement;

abstract class AchievementDefinition extends BaseAchievementDefinition
{
    /**
     * Get the achievement(s) that must be completed before this one
     * Can return:
     * - A single key (string): 'achievement_key'
     * - A single class (string): FirstPostAchievement::class
     * - Multiple mixed (array): ['achievement_key', SecondPostAchievement::class]
     */
    public static function prerequisite(): string|array|null
    {
        return null;
    }

    /**
     * Category for grouping achievements
     */
    public static function category(): string
    {
        return 'general';
    }

    /**
     * Check if this achievement can be started (prerequisites met)
     */
    public static function canStart(Model $achiever): bool
    {
        $prerequisites = static::prerequisite();

        if (! $prerequisites) {
            return true;
        }

        // Convert single prerequisite to array for uniform handling
        if (is_string($prerequisites)) {
            $prerequisites = [$prerequisites];
        }

        // Check if all prerequisites are unlocked
        foreach ($prerequisites as $prerequisite) {
            // Extract key from class name if it's a class
            $prerequisiteKey = static::extractAchievementKey($prerequisite);

            $isUnlocked = AchieverAchievement::where('achiever_id', $achiever->id)
                ->where('achiever_type', get_class($achiever))
                ->where('achievement_key', $prerequisiteKey)
                ->whereNotNull('unlocked_at')
                ->exists();

            if (! $isUnlocked) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract achievement key from either a string key or AchievementDefinition class
     */
    protected static function extractAchievementKey(string $prerequisite): string
    {
        // If it's a class name (contains backslashes or ends with Achievement)
        if (str_contains($prerequisite, '\\') || class_exists($prerequisite)) {
            // Check if it's a valid AchievementDefinition class
            if (
                class_exists($prerequisite) &&
                is_subclass_of($prerequisite, BaseAchievementDefinition::class) &&
                method_exists($prerequisite, 'key')
            ) {
                return $prerequisite::key();
            }
        }

        // Otherwise, treat it as a direct key
        return $prerequisite;
    }

    /**
     * Helper: Get count for a relationship
     */
    protected static function getCount(Model $achiever, string $relation): int
    {
        return $achiever->$relation()->count();
    }

    /**
     * Helper: Check if achiever has minimum count for a relationship
     */
    protected static function hasMinimumCount(Model $achiever, string $relation, int $count): bool
    {
        return $achiever->$relation()->count() >= $count;
    }

    /**
     * Helper: Check if achiever has exact count for a relationship
     */
    protected static function hasExactCount(Model $achiever, string $relation, int $count): bool
    {
        return $achiever->$relation()->count() === $count;
    }

    /**
     * Helper: Check if achiever has count within range for a relationship
     */
    protected static function hasCountInRange(Model $achiever, string $relation, int $min, int $max): bool
    {
        $count = $achiever->$relation()->count();

        return $count >= $min && $count <= $max;
    }

    /**
     * Helper: Check if achiever was created within time period
     */
    protected static function createdWithin(Model $achiever, string $period): bool
    {
        return $achiever->created_at->gt(now()->sub($period));
    }

    /**
     * Helper: Check if relationship has records within time period
     */
    protected static function hasRecentActivity(Model $achiever, string $relation, string $period, int $count = 1): bool
    {
        return $achiever->$relation()
            ->where('created_at', '>', now()->sub($period))
            ->count() >= $count;
    }
}

<?php

namespace Ram64\LaravelMerit\Achievements;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Models\AchieverAchievement;

abstract class AchievementDefinition extends BaseAchievementDefinition
{
    /**
     * Get the achievement that must be completed before this one
     */
    public static function prerequisite(): ?string
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
        $prerequisite = static::prerequisite();

        if (! $prerequisite) {
            return true;
        }

        return AchieverAchievement::where('achiever_id', $achiever->id)
            ->where('achiever_type', get_class($achiever))
            ->where('achievement_key', $prerequisite)
            ->whereNotNull('unlocked_at')
            ->exists();
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

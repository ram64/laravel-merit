<?php

namespace Ram64\LaravelMerit\Definitions\Achievements;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The class consuming applications extend: the base contract plus helpers for
 * the commonest progress checks.
 */
abstract class AchievementDefinition extends BaseAchievementDefinition
{
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
        $createdAt = $achiever->getAttribute($achiever->getCreatedAtColumn());

        // A model without timestamps has no created_at at all, which used to
        // be a fatal call on null rather than a false.
        if (! $createdAt instanceof CarbonInterface) {
            return false;
        }

        return $createdAt->gt(now()->sub($period));
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

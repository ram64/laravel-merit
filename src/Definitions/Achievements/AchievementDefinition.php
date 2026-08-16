<?php

namespace Ram64\LaravelMerit\Definitions\Achievements;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Models\AchieverAchievement;

abstract class AchievementDefinition extends BaseAchievementDefinition
{
    /**
     * Unlocked achievement keys per achiever, held only for the length of an
     * open batch window. Shared across every definition, because it is keyed
     * by achiever rather than by achievement.
     *
     * @var array<string, array<string, int>>
     */
    private static array $unlockedKeys = [];

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

        $unlocked = self::$unlockedKeys[self::achieverKey($achiever)]
            ?? static::fetchUnlockedKeys($achiever);

        // All prerequisites must be unlocked, not any of them.
        foreach ($prerequisites as $prerequisite) {
            // Extract key from class name if it's a class
            if (! isset($unlocked[static::extractAchievementKey($prerequisite)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch the achiever's unlocked keys once, and hold them until
     * flushUnlockedKeys() — so a pass over every achievement costs one query
     * rather than one per prerequisite per achievement.
     *
     * Callers must pair this with flushUnlockedKeys() in a finally: while a
     * window is open, canStart() answers from the held set, and an unlock that
     * happens outside noteUnlocked() will not be seen.
     */
    public static function primeUnlockedKeys(Model $achiever): void
    {
        self::$unlockedKeys[self::achieverKey($achiever)] = static::fetchUnlockedKeys($achiever);
    }

    /**
     * Close the batch window opened by primeUnlockedKeys().
     */
    public static function flushUnlockedKeys(?Model $achiever = null): void
    {
        if ($achiever === null) {
            self::$unlockedKeys = [];

            return;
        }

        unset(self::$unlockedKeys[self::achieverKey($achiever)]);
    }

    /**
     * Record an unlock against an open batch window.
     *
     * This is what lets a chain complete in a single pass: a prerequisite
     * unlocked earlier in the run has to be visible to its dependents later in
     * the same run. A no-op when no window is open.
     */
    public static function noteUnlocked(Model $achiever, string $achievementKey): void
    {
        $achieverKey = self::achieverKey($achiever);

        if (isset(self::$unlockedKeys[$achieverKey])) {
            self::$unlockedKeys[$achieverKey][$achievementKey] = 1;
        }
    }

    /**
     * Every achievement key this achiever has unlocked.
     *
     * @return array<string, int>
     */
    protected static function fetchUnlockedKeys(Model $achiever): array
    {
        return array_flip(
            AchieverAchievement::where('achiever_id', $achiever->getKey())
                ->where('achiever_type', get_class($achiever))
                ->whereNotNull('unlocked_at')
                ->pluck('achievement_key')
                ->all()
        );
    }

    /**
     * Identify one achiever within the held set.
     */
    private static function achieverKey(Model $achiever): string
    {
        return get_class($achiever).':'.$achiever->getKey();
    }

    /**
     * Extract achievement key from either a string key or AchievementDefinition class
     */
    protected static function extractAchievementKey(string $prerequisite): string
    {
        // If it's a class name (contains backslashes or ends with Achievement)
        if (str_contains($prerequisite, '\\') || class_exists($prerequisite)) {
            // Check if it's a valid AchievementDefinition class. key() is
            // abstract on the base, so a subclass always has it.
            if (
                class_exists($prerequisite) &&
                is_subclass_of($prerequisite, BaseAchievementDefinition::class)
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

<?php

namespace LaravelMerit\Achievements;

/**
 * Base class for all Achievements.
 * Implement the abstract methods to define specific achievements.
 */
abstract class BaseAchievement
{
    /**
     * Get the unique key for the achievement.
     * @return string
     */
    abstract public static function key(): string;

    /**
     * Get the name of the achievement.
     * @return string
     */
    abstract public static function name(): string;

    /**
     * Get the description of the achievement.
     * @return string
     */
    abstract public static function description(): string;

    /**
     * Get the goal for the achievement.
     * The number of times an action must be performed to unlock the achievement.
     * @return int
     */
    public static function goal(): int
    {
        return 1;
    }

    /**
     * Determine if the achievement is hidden.
     * If true, the achievement won't be displayed until unlocked.
     * @return bool
     */
    public static function isHidden(): bool
    {
        return false;
    }

    /**
     * Determine if the achiever qualifies for this achievement.
     * @param mixed $achiever
     * @return void
     */
    abstract public static function qualifies($achiever): bool;
}

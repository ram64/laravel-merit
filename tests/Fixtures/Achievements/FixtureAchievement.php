<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;

/**
 * Shared base for the fixture definitions.
 *
 * It lives one directory above the fixture groups so discovery — which is
 * pointed at a single group directory at a time — never picks it up.
 */
abstract class FixtureAchievement extends AchievementDefinition
{
    public static function name(?Model $achiever = null): string
    {
        return static::key().' name';
    }

    public static function description(?Model $achiever = null): string
    {
        return static::key().' description';
    }

    public static function goal(): int
    {
        return 1;
    }

    /**
     * Progress is the achiever's `points`, capped at the goal.
     */
    public static function calculateCurrentProgress(Model $achiever): int
    {
        return min((int) $achiever->points, static::goal());
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        return static::calculateCurrentProgress($achiever) >= static::goal();
    }
}

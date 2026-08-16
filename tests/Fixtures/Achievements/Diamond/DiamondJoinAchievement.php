<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Diamond;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * The join of the diamond: both branches must be unlocked, not either one.
 *
 * Alphabetically first, so discovery finds it before anything it depends on.
 */
class DiamondJoinAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'diamond_join';
    }

    public static function prerequisite(): string|array|null
    {
        return ['diamond_left', 'diamond_right'];
    }
}

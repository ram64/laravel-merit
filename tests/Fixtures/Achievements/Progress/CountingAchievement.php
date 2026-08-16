<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * A multi-step achievement, so progress transitions are observable between
 * "started" and "completed".
 */
class CountingAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'counting';
    }

    public static function goal(): int
    {
        return 3;
    }

    public static function category(): string
    {
        return 'progression';
    }
}

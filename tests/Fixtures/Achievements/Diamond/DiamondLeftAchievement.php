<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Diamond;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class DiamondLeftAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'diamond_left';
    }

    public static function prerequisite(): string|array|null
    {
        return 'diamond_root';
    }
}

<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Diamond;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class DiamondRightAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'diamond_right';
    }

    public static function prerequisite(): string|array|null
    {
        return DiamondRootAchievement::class;
    }
}

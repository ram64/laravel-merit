<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Cycle;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class CycleTwoAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'cycle_two';
    }

    public static function prerequisite(): string|array|null
    {
        return 'cycle_one';
    }
}

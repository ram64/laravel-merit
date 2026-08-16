<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Cycle;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class CycleOneAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'cycle_one';
    }

    public static function prerequisite(): string|array|null
    {
        return 'cycle_two';
    }
}
